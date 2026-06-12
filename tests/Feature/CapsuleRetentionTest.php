<?php

namespace Tests\Feature;

use App\Jobs\CreateOsintCapsule;
use App\Models\User;
use App\Support\CapsuleRetentionPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CapsuleRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_ingest_dispatches_capsule_with_retention_policy(): void
    {
        Queue::fake();

        $admin = User::factory()->admin()->create();
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

            Queue::assertPushed(CreateOsintCapsule::class, function (CreateOsintCapsule $job): bool {
                return $job->retentionPolicy === CapsuleRetentionPolicy::TELEGRAM;
            });
        } finally {
            @unlink($filePath);
        }
    }

    public function test_prune_command_deletes_expired_capsules(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 6, 11, 10, 0, 0, 'UTC'));
        Process::fake(fn () => Process::result(json_encode([[
            'display_name' => 'Expired Stealer',
            'retention_policy' => CapsuleRetentionPolicy::STEALER,
            'retention_label' => 'Stealer logs',
            'retention_expires_at' => '2026-06-10 10:00:00',
        ]])));

        $capsuleDir = storage_path('framework/testing/capsules');
        File::ensureDirectoryExists($capsuleDir);
        $capsulePath = $capsuleDir.'/capsule_expired.db';
        File::put($capsulePath, 'placeholder');

        try {
            $this->artisan('capsules:prune-expired', ['--path' => $capsuleDir])
                ->assertExitCode(0);

            $this->assertFileDoesNotExist($capsulePath);
        } finally {
            File::deleteDirectory($capsuleDir);
            CarbonImmutable::setTestNow();
        }
    }

    public function test_prune_command_dry_run_keeps_expired_capsules(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 6, 11, 10, 0, 0, 'UTC'));
        Process::fake(fn () => Process::result(json_encode([[
            'display_name' => 'Expired ULP',
            'retention_policy' => CapsuleRetentionPolicy::ULP_LOG,
            'retention_label' => 'ULP logs',
            'retention_expires_at' => '2026-06-10 10:00:00',
        ]])));

        $capsuleDir = storage_path('framework/testing/capsules-dry-run');
        File::ensureDirectoryExists($capsuleDir);
        $capsulePath = $capsuleDir.'/capsule_expired.db';
        File::put($capsulePath, 'placeholder');

        try {
            $this->artisan('capsules:prune-expired', [
                '--path' => $capsuleDir,
                '--dry-run' => true,
            ])->assertExitCode(0);

            $this->assertFileExists($capsulePath);
        } finally {
            File::deleteDirectory($capsuleDir);
            CarbonImmutable::setTestNow();
        }
    }
}
