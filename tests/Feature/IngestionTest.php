<?php

namespace Tests\Feature;

use App\Models\Leak;
use App\Jobs\IngestLeakFile;
use App\Support\QGrep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class IngestionTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        File::ensureDirectoryExists(QGrep::storagePath());
        File::ensureDirectoryExists(QGrep::indexPath());
        Process::fake();
    }

    /**
     * Test that unstructured data is ingested correctly.
     */
    public function test_ingestion_handles_unstructured_data_without_error(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'osint_test_');
        File::put($tempFile, "line 1\nline 2\nline 3");

        try {
            $job = new IngestLeakFile(
                $tempFile,
                'Test Leak',
                '2026-06-12',
                'UNSTRUCTURED'
            );

            $job->handle();

            $this->assertDatabaseHas('leaks', [
                'display_name' => 'Test Leak',
                'total_lines' => 3,
            ]);

            $leak = Leak::first();
            $this->assertTrue(File::exists($leak->file_path));
            $this->assertEquals("line 1\nline 2\nline 3\n", File::get($leak->file_path));

            Process::assertRan(function ($process) {
                return str_contains($process->command[1], 'index');
            });
        } finally {
            if (isset($leak) && File::exists($leak->file_path)) {
                File::delete($leak->file_path);
            }
            if (File::exists($tempFile)) {
                File::delete($tempFile);
            }
        }
    }

    public function test_ingestion_preserves_rows_with_nul_and_invalid_utf8_bytes(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'osint_test_');
        File::put($tempFile, "alpha\0one\nbeta\xFFtwo\nUPPER@example.COM\n");

        try {
            $job = new IngestLeakFile(
                $tempFile,
                'Binary-ish SQL Dump',
                '2026-06-12',
                'UNSTRUCTURED'
            );

            $job->handle();

            $leak = Leak::first();
            $this->assertEquals(3, $leak->total_lines);

            $content = File::get($leak->file_path);
            $this->assertStringContainsString('alpha one', $content);
            $this->assertStringContainsString('betatwo', $content);
            $this->assertStringContainsString('upper@example.com', $content);
        } finally {
            if (isset($leak) && File::exists($leak->file_path)) {
                File::delete($leak->file_path);
            }
            if (File::exists($tempFile)) {
                File::delete($tempFile);
            }
        }
    }
}
