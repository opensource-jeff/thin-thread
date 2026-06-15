<?php

namespace App\Console\Commands;

use App\Support\DuckDB;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use JsonException;
use SplFileInfo;

class PruneExpiredCapsules extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'capsules:prune-expired
        {--dry-run : Report expired capsules without deleting them}
        {--path= : Override the capsule directory}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete DuckDB capsule files whose retention metadata has expired';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $capsuleDir = $this->option('path') ?: storage_path('app/osint_capsules');

        if (! File::exists($capsuleDir)) {
            $this->info('No capsule directory exists.');

            return self::SUCCESS;
        }

        $now = CarbonImmutable::now('UTC');
        $isDryRun = (bool) $this->option('dry-run');
        $deleted = 0;
        $expired = 0;
        $skipped = 0;

        foreach (File::files($capsuleDir) as $file) {
            if ($file->getExtension() !== 'db') {
                continue;
            }

            $metadata = $this->readRetentionMetadata($file);

            if ($metadata === null) {
                $skipped++;

                continue;
            }

            $expiresAt = $metadata['retention_expires_at'] ?? null;

            if ($expiresAt === null || $expiresAt === '') {
                $this->line($file->getFilename().' retained indefinitely.');

                continue;
            }

            try {
                $expiresAt = CarbonImmutable::parse($expiresAt, 'UTC');
            } catch (\Throwable) {
                $this->warn($file->getFilename().' has an invalid retention_expires_at value and was skipped.');
                $skipped++;

                continue;
            }

            if ($expiresAt->greaterThan($now)) {
                $this->line($file->getFilename().' retained until '.$expiresAt->toDateTimeString().' UTC.');

                continue;
            }

            $expired++;

            if ($isDryRun) {
                $this->warn('[dry-run] Would delete '.$file->getFilename().' expired '.$expiresAt->toDateTimeString().' UTC.');

                continue;
            }

            File::delete($file->getPathname());
            $deleted++;

            $this->warn('Deleted '.$file->getFilename().' expired '.$expiresAt->toDateTimeString().' UTC.');
        }

        $this->info("Expired: {$expired}; deleted: {$deleted}; skipped: {$skipped}.");

        return self::SUCCESS;
    }

    /**
     * Read retention metadata from a capsule.
     *
     * @return array<string, mixed>|null
     */
    private function readRetentionMetadata(SplFileInfo $file): ?array
    {
        $preamble = DuckDB::preamble();
        $sql = <<<SQL
{$preamble}
SELECT
    display_name,
    retention_policy,
    retention_label,
    CAST(retention_expires_at AS VARCHAR) AS retention_expires_at
FROM meta
LIMIT 1;
SQL;

        $result = DuckDB::process(20)->run([
            DuckDB::binary(),
            '-json',
            $file->getPathname(),
            '-c',
            $sql,
        ]);

        if (! $result->successful()) {
            $this->warn($file->getFilename().' has no readable retention metadata and was skipped.');

            return null;
        }

        try {
            $rows = json_decode($result->output(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->warn($file->getFilename().' returned invalid retention metadata and was skipped.');

            return null;
        }

        return $rows[0] ?? null;
    }
}

