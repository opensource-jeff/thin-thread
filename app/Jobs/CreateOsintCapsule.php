<?php

namespace App\Jobs;

use App\Support\CapsuleRetentionPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

class CreateOsintCapsule implements ShouldQueue
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
        $capsuleDir = storage_path('app/osint_capsules');
        File::ensureDirectoryExists($capsuleDir);

        $capsulePath = "{$capsuleDir}/capsule_{$uuid}.db";
        $sqlPath = "{$capsuleDir}/ingest_{$uuid}.sql";
        $importPath = "{$capsuleDir}/ingest_{$uuid}.csv";
        $ingestedAt = CarbonImmutable::now('UTC');
        $expiresAt = CapsuleRetentionPolicy::expiresAt($this->retentionPolicy, $ingestedAt);

        $lineCount = $this->writeNormalizedCsv($this->filePath, $importPath);

        $displayName = $this->sqlString($this->displayName);
        $leakDate = $this->sqlString($this->leakDate);
        $dataFormat = $this->sqlString($this->dataFormat);
        $retentionPolicy = $this->sqlString($this->retentionPolicy);
        $retentionLabel = $this->sqlString(CapsuleRetentionPolicy::label($this->retentionPolicy));
        $retentionExpiresAt = $expiresAt
            ? $this->sqlString($expiresAt->format('Y-m-d H:i:s'))
            : 'NULL';
        $ingestedAtSql = $this->sqlString($ingestedAt->format('Y-m-d H:i:s'));
        $importPathSql = $this->sqlString($importPath);

        $insertSql = $lineCount > 0
            ? "INSERT INTO raw_data SELECT column0 FROM read_csv({$importPathSql}, delim=',', quote='\"', escape='\"', header=False, columns={'column0': 'VARCHAR'}, ignore_errors=False, auto_detect=False);"
            : '-- Source file contained no rows.';

        // Prepare SQL script
        $sql = <<<SQL
        PRAGMA memory_limit='5GB';
        PRAGMA threads=4;

        CREATE TABLE raw_data (raw_text VARCHAR);

        {$insertSql}

        CREATE TABLE meta (
        display_name VARCHAR,
        leak_date DATE,
        data_format VARCHAR,
        retention_policy VARCHAR,
        retention_label VARCHAR,
        retention_expires_at TIMESTAMP,
        ingested_at TIMESTAMP,
        total_lines BIGINT
        );

        INSERT INTO meta (display_name, leak_date, data_format, retention_policy, retention_label, retention_expires_at, ingested_at, total_lines)
        SELECT {$displayName}, {$leakDate}, {$dataFormat}, {$retentionPolicy}, {$retentionLabel}, {$retentionExpiresAt}, {$ingestedAtSql}, count(*) FROM raw_data;

        LOAD fts;
        PRAGMA create_fts_index('raw_data', 'raw_text', 'raw_text', stemmer='none', stopwords='none');
        SQL;

        File::put($sqlPath, $sql);

        // Run DuckDB CLI
        $result = Process::timeout($this->timeout)
            ->env($this->duckDbEnvironment())
            ->run(['duckdb', '-bail', $capsulePath, '-f', $sqlPath]);

        if ($result->successful()) {
            // Only delete the source file if it's in our temporary/upload directory
            if (str_starts_with($this->filePath, storage_path('app/osint_capsules'))) {
                File::delete($this->filePath);
            }
            File::delete($sqlPath);
            File::delete($importPath);

            Log::info("DuckDB Ingestion Successful: {$this->displayName}", [
                'capsule' => basename($capsulePath),
                'rows' => $lineCount,
            ]);
        } else {
            File::delete($importPath);

            Log::error("DuckDB Ingestion Failed: {$this->displayName}", [
                'error' => $result->errorOutput(),
            ]);

            throw new RuntimeException('DuckDB Ingestion Failed: '.$result->errorOutput());
        }
    }

    private function writeNormalizedCsv(string $sourcePath, string $importPath): int
    {
        $source = fopen($sourcePath, 'rb');
        if ($source === false) {
            throw new RuntimeException("Unable to open source file: {$sourcePath}");
        }

        $target = fopen($importPath, 'wb');
        if ($target === false) {
            fclose($source);
            throw new RuntimeException("Unable to create import file: {$importPath}");
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

                if (fputcsv($target, [$line], ',', '"', '', "\n") === false) {
                    throw new RuntimeException("Unable to write import row for: {$sourcePath}");
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

    private function sqlString(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    /**
     * DuckDB looks for installed extensions under HOME; web/queue workers often
     * run without it, which makes LOAD fts fail against /.duckdb.
     *
     * @return array<string, string>
     */
    private function duckDbEnvironment(): array
    {
        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? null);

        if (! $home && function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $user = posix_getpwuid(posix_geteuid());
            $home = is_array($user) ? ($user['dir'] ?? null) : null;
        }

        return ['HOME' => $home ?: base_path()];
    }
}
