<?php

namespace App\Jobs;

use App\Models\Leak;
use App\Support\QGrep;
use App\Support\CapsuleRetentionPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class IngestLeakFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 86400;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $filePath,
        public string $displayName,
        public string $leakDate,
        public string $dataFormat,
        public string $retentionPolicy = CapsuleRetentionPolicy::BREACH
    ) {}

    /**
     * The number of lines per file chunk.
     */
    const CHUNK_SIZE = 1000000;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $leakDir = QGrep::storagePath();
        File::ensureDirectoryExists($leakDir);

        $ingestedAt = CarbonImmutable::now('UTC');
        $expiresAt = CapsuleRetentionPolicy::expiresAt($this->retentionPolicy, $ingestedAt);

        $chunks = $this->processAndChunkFile($this->filePath, $leakDir, $ingestedAt, $expiresAt);

        if (count($chunks) === 0) {
            Log::warning("Ingestion skipped: No data found in {$this->displayName}");
            return;
        }

        // 2. Trigger qgrep indexing once for all chunks
        $this->updateQGrepIndex();

        // 3. Cleanup source if needed
        if (str_starts_with($this->filePath, storage_path('app'))) {
            File::delete($this->filePath);
        }

        Log::info("Leak Ingestion Successful: {$this->displayName}", [
            'chunks' => count($chunks),
            'total_rows' => array_sum(array_column($chunks, 'total_lines')),
        ]);
    }

    private function processAndChunkFile(string $sourcePath, string $leakDir, $ingestedAt, $expiresAt): array
    {
        $source = fopen($sourcePath, 'rb');
        if ($source === false) {
            throw new RuntimeException("Unable to open source file: {$sourcePath}");
        }

        $previousSubstitute = mb_substitute_character();
        mb_substitute_character('none');

        $chunks = [];
        $currentChunkLines = 0;
        $totalLines = 0;
        $currentTarget = null;
        $currentPath = null;

        try {
            while (($line = fgets($source)) !== false) {
                if ($currentTarget === null || $currentChunkLines >= self::CHUNK_SIZE) {
                    // Close previous chunk
                    if ($currentTarget) {
                        fclose($currentTarget);
                        $chunks[] = $this->registerChunk($currentPath, $currentChunkLines, $ingestedAt, $expiresAt);
                    }

                    // Open new chunk
                    $uuid = (string) Str::uuid();
                    $currentPath = "{$leakDir}/leak_{$uuid}.txt";
                    $currentTarget = fopen($currentPath, 'wb');
                    $currentChunkLines = 0;
                }

                $line = rtrim($line, "\r\n");
                $line = mb_convert_encoding($line, 'UTF-8', 'UTF-8');
                $line = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $line) ?? '';
                $line = mb_strtolower($line, 'UTF-8');

                fwrite($currentTarget, $line . "\n");
                $currentChunkLines++;
                $totalLines++;
            }

            // Close the final chunk
            if ($currentTarget) {
                fclose($currentTarget);
                $chunks[] = $this->registerChunk($currentPath, $currentChunkLines, $ingestedAt, $expiresAt);
            }
        } finally {
            mb_substitute_character($previousSubstitute);
            fclose($source);
        }

        return $chunks;
    }

    private function registerChunk(string $path, int $lineCount, $ingestedAt, $expiresAt): Leak
    {
        return Leak::create([
            'display_name' => $this->displayName,
            'file_path' => $path,
            'leak_date' => $this->leakDate,
            'data_format' => $this->dataFormat,
            'retention_policy' => $this->retentionPolicy,
            'retention_label' => CapsuleRetentionPolicy::label($this->retentionPolicy),
            'retention_expires_at' => $expiresAt,
            'ingested_at' => $ingestedAt,
            'total_lines' => $lineCount,
        ]);
    }

    private function updateQGrepIndex(): void
    {
        $indexDir = QGrep::indexPath();
        File::ensureDirectoryExists($indexDir);
        $indexFile = "{$indexDir}/leaks.qf";

        $result = QGrep::process($this->timeout)
            ->run([QGrep::binary(), 'index', $indexFile, QGrep::storagePath()]);

        if (! $result->successful()) {
            Log::error("qgrep Indexing Failed", [
                'error' => $result->errorOutput(),
            ]);
            throw new RuntimeException('qgrep Indexing Failed: '.$result->errorOutput());
        }
    }
}

