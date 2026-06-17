<?php

namespace Tests\Feature;

use App\Models\Leak;
use App\Models\User;
use App\Support\QGrep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class AdminCapsuleDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_leak_delete_removes_record_and_file(): void
    {
        $this->withoutMiddleware();
        Process::fake();
        $user = User::factory()->create(['is_admin' => true]);
        
        $leakDir = QGrep::storagePath();
        File::ensureDirectoryExists($leakDir);
        $leakFile = $leakDir . '/delete_test.txt';
        File::put($leakFile, 'content');

        $leak = Leak::create([
            'display_name' => 'Delete Test',
            'file_path' => $leakFile,
            'leak_date' => '2026-06-12',
            'data_format' => 'UNSTRUCTURED',
            'retention_policy' => 'breach',
            'retention_label' => 'Breach',
            'ingested_at' => now(),
            'total_lines' => 1,
        ]);

        $this->assertDatabaseHas('leaks', ['id' => $leak->id]);
        $this->assertFileExists($leakFile);

        $response = $this->actingAs($user)
            ->delete("/admin/capsules/{$leak->id}");

        $response->assertStatus(302);
        $this->assertDatabaseMissing('leaks', ['id' => $leak->id]);
        $this->assertFileDoesNotExist($leakFile);

        Process::assertRan(function ($process) {
            return str_contains($process->command[1], 'index');
        });
    }
}
