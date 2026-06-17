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
     * Execute the job.
     */
    public function handle(): void
    {
        $uuid = (string) Str::uuid();
        $leakDir = QGrep::storagePath();
        File::ensureDirectoryExists($leakDir);

        $normalizedPath = "{$leakDir}/leak_{$uuid}.txt";
        $ingestedAt = CarbonImmutable::now('UTC');
        $expiresAt = CapsuleRetentionPolicy::expiresAt($this->retentionPolicy, $ingestedAt);

        $lineCount = $this->writeNormalizedFile($this->filePath, $normalizedPath);

        if ($lineCount === 0) {
            File::delete($normalizedPath);
            Log::warning("Ingestion skipped: Source file contained no rows for {$this->displayName}");
            return;
        }

        // 1. Store metadata in MariaDB
        Leak::create([
            'display_name' => $this->displayName,
            'file_path' => $normalizedPath,
            'leak_date' => $this->leakDate,
            'data_format' => $this->dataFormat,
            'retention_policy' => $this->retentionPolicy,
            'retention_label' => CapsuleRetentionPolicy::label($this->retentionPolicy),
            'retention_expires_at' => $expiresAt,
            'ingested_at' => $ingestedAt,
            'total_lines' => $lineCount,
        ]);

        // 2. Trigger qgrep indexing
        $this->updateQGrepIndex();

        // 3. Cleanup source if needed
        if (str_starts_with($this->filePath, storage_path('app'))) {
            File::delete($this->filePath);
        }

        Log::info("Leak Ingestion Successful: {$this->displayName}", [
            'file' => basename($normalizedPath),
            'rows' => $lineCount,
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

    private function writeNormalizedFile(string $sourcePath, string $targetPath): int
    {
        $source = fopen($sourcePath, 'rb');
        if ($source === false) {
            throw new RuntimeException("Unable to open source file: {$sourcePath}");
        }

        $target = fopen($targetPath, 'wb');
        if ($target === false) {
            fclose($source);
            throw new RuntimeException("Unable to create target file: {$targetPath}");
        }

        $previousSubstitute = mb_substitute_character();
        mb_substitute_character('none');

        $lineCount = 0;

        try {
            while (($line = fgets($source)) !== false) {
                $line = rtrim($line, "\r\n");
                $line = mb_convert_encoding($line, 'UTF-8', 'UTF-8');
                $line = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $line) ?? '';
                $line = mb_strtolower($line, 'UTF-8');

                if (fwrite($target, $line . "\n") === false) {
                    throw new RuntimeException("Unable to write row for: {$sourcePath}");
                }

                $lineCount++;
            }
        } finally {
            mb_substitute_character($previousSubstitute);
            fclose($source);
            fclose($target);
        }

        return $lineCount;
    }
}

