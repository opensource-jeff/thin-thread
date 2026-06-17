<?php

namespace Tests\Feature;

use App\Models\Leak;
use App\Models\User;
use App\Jobs\IngestLeakFile;
use App\Support\CapsuleRetentionPolicy;
use App\Support\QGrep;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CapsuleRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_ingest_dispatches_leak_with_retention_policy(): void
    {
        $this->withoutMiddleware();
        Queue::fake();

        $admin = User::factory()->create(['is_admin' => true]);
        $filePath = tempnam(sys_get_temp_dir(), 'thin-thread-retention-');
        file_put_contents($filePath, 'example row');

        try {
            $this->actingAs($admin)
                ->post(route('admin.ingest'), [
                    'file_path' => $filePath,
                    'display_name' => 'Telegram Collection',
                    'leak_date' => '2026-06-11',
                    'classification' => 'CSV',
                    'retention_policy' => CapsuleRetentionPolicy::TELEGRAM,
                ])
                ->assertRedirect()
                ->assertSessionHasNoErrors();

            Queue::assertPushed(IngestLeakFile::class, function (IngestLeakFile $job): bool {
                return $job->retentionPolicy === CapsuleRetentionPolicy::TELEGRAM;
            });
        } finally {
            @unlink($filePath);
        }
    }

    public function test_prune_command_deletes_expired_leaks(): void
    {
        Process::fake();
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 6, 11, 10, 0, 0, 'UTC'));
        
        $leakFile = QGrep::storagePath() . '/expired_leak.txt';
        File::ensureDirectoryExists(QGrep::storagePath());
        File::put($leakFile, 'content');

        Leak::create([
            'display_name' => 'Expired Leak',
            'file_path' => $leakFile,
            'leak_date' => '2026-06-12',
            'data_format' => 'UNSTRUCTURED',
            'retention_policy' => CapsuleRetentionPolicy::STEALER,
            'retention_label' => 'Stealer',
            'retention_expires_at' => '2026-06-10 10:00:00',
            'ingested_at' => now(),
            'total_lines' => 1,
        ]);

        try {
            $this->artisan('capsules:prune-expired')
                ->assertExitCode(0);

            $this->assertDatabaseMissing('leaks', ['display_name' => 'Expired Leak']);
            $this->assertFileDoesNotExist($leakFile);
            
            Process::assertRan(function ($process) {
                return str_contains($process->command[1], 'index');
            });
        } finally {
            CarbonImmutable::setTestNow();
            if (File::exists($leakFile)) File::delete($leakFile);
        }
    }

    public function test_prune_command_dry_run_keeps_expired_leaks(): void
    {
        Process::fake();
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 6, 11, 10, 0, 0, 'UTC'));
        
        $leakFile = QGrep::storagePath() . '/expired_leak_dry.txt';
        File::ensureDirectoryExists(QGrep::storagePath());
        File::put($leakFile, 'content');

        Leak::create([
            'display_name' => 'Expired Leak Dry Run',
            'file_path' => $leakFile,
            'leak_date' => '2026-06-12',
            'data_format' => 'UNSTRUCTURED',
            'retention_policy' => CapsuleRetentionPolicy::STEALER,
            'retention_label' => 'Stealer',
            'retention_expires_at' => '2026-06-10 10:00:00',
            'ingested_at' => now(),
            'total_lines' => 1,
        ]);

        try {
            $this->artisan('capsules:prune-expired', ['--dry-run' => true])
                ->assertExitCode(0);

            $this->assertDatabaseHas('leaks', ['display_name' => 'Expired Leak Dry Run']);
            $this->assertFileExists($leakFile);
        } finally {
            CarbonImmutable::setTestNow();
            if (File::exists($leakFile)) File::delete($leakFile);
        }
    }
}
