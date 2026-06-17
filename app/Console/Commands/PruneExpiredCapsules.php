<?php

namespace App\Console\Commands;

use App\Models\Leak;
use App\Support\QGrep;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class PruneExpiredCapsules extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'capsules:prune-expired
        {--dry-run : Report expired leaks without deleting them}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete leak files whose retention metadata has expired in MariaDB';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $now = CarbonImmutable::now('UTC');
        $isDryRun = (bool) $this->option('dry-run');

        $expiredLeaks = Leak::query()
            ->whereNotNull('retention_expires_at')
            ->where('retention_expires_at', '<=', $now)
            ->get();

        if ($expiredLeaks->isEmpty()) {
            $this->info('No expired leaks found.');
            return self::SUCCESS;
        }

        $deleted = 0;

        foreach ($expiredLeaks as $leak) {
            $path = $leak->file_path;
            $displayName = $leak->display_name;
            $expiresAt = $leak->retention_expires_at;

            if ($isDryRun) {
                $this->warn("[dry-run] Would delete '{$displayName}' expired {$expiresAt->toDateTimeString()} UTC.");
                continue;
            }

            if (File::exists($path)) {
                File::delete($path);
            }

            $leak->delete();
            $deleted++;

            $this->warn("Deleted '{$displayName}' expired {$expiresAt->toDateTimeString()} UTC.");
        }

        if ($deleted > 0) {
            $this->updateQGrepIndex();
        }

        $this->info("Expired leaks: {$expiredLeaks->count()}; deleted: {$deleted}.");

        return self::SUCCESS;
    }

    private function updateQGrepIndex(): void
    {
        $indexDir = QGrep::indexPath();
        File::ensureDirectoryExists($indexDir);
        $indexFile = "{$indexDir}/leaks.qf";

        QGrep::process(300)
            ->run([QGrep::binary(), 'index', $indexFile, QGrep::storagePath()]);
    }
}

