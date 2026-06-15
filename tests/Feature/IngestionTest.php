<?php

namespace Tests\Feature;

use App\Support\DuckDB;
use App\Jobs\CreateOsintCapsule;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class IngestionTest extends TestCase
{
    /**
     * Test that unstructured data is ingested correctly using read_csv.
     * This verifies the fix for the Binder Error caused by using read_text with columns.
     */
    public function test_ingestion_handles_unstructured_data_without_error(): void
    {
        $before = $this->capsuleFiles();

        $tempFile = tempnam(sys_get_temp_dir(), 'osint_test_');
        File::put($tempFile, "line 1\nline 2\nline 3");

        try {
            $job = new CreateOsintCapsule(
                $tempFile,
                'Test Leak',
                '2026-06-12',
                'UNSTRUCTURED'
            );

            // This should not throw a RuntimeException
            $job->handle();

            $dbFiles = $this->newDbFiles($before);
            $this->assertCount(1, $dbFiles);
            $this->assertSame(3, $this->metaTotalLines($dbFiles[0]));
        } finally {
            $this->deleteNewCapsuleFiles($before);

            if (File::exists($tempFile)) {
                File::delete($tempFile);
            }
        }
    }

    /**
     * Test that JSON data is ingested correctly.
     */
    public function test_ingestion_handles_json_data_without_error(): void
    {
        $before = $this->capsuleFiles();

        $tempFile = tempnam(sys_get_temp_dir(), 'osint_test_');
        File::put($tempFile, '{"email": "test@example.com", "pass": "secret"}'."\n".'{"email": "admin@example.com", "pass": "123456"}');

        try {
            $job = new CreateOsintCapsule(
                $tempFile,
                'Test JSON Leak',
                '2026-06-12',
                'JSON'
            );

            // This should not throw a RuntimeException
            $job->handle();

            $dbFiles = $this->newDbFiles($before);
            $this->assertCount(1, $dbFiles);
            $this->assertSame(2, $this->metaTotalLines($dbFiles[0]));
        } finally {
            $this->deleteNewCapsuleFiles($before);

            if (File::exists($tempFile)) {
                File::delete($tempFile);
            }
        }
    }

    public function test_ingestion_preserves_rows_with_nul_and_invalid_utf8_bytes(): void
    {
        $before = $this->capsuleFiles();

        $tempFile = tempnam(sys_get_temp_dir(), 'osint_test_');
        File::put($tempFile, "alpha\0one\nbeta\xFFtwo\nUPPER@example.COM\n");

        try {
            $job = new CreateOsintCapsule(
                $tempFile,
                'Binary-ish SQL Dump',
                '2026-06-12',
                'UNSTRUCTURED'
            );

            $job->handle();

            $dbFiles = $this->newDbFiles($before);
            $this->assertCount(1, $dbFiles);
            $this->assertSame(3, $this->metaTotalLines($dbFiles[0]));

            $rows = $this->rawRows($dbFiles[0]);
            $this->assertContains('alpha one', $rows);
            $this->assertContains('betatwo', $rows);
            $this->assertContains('upper@example.com', $rows);
        } finally {
            $this->deleteNewCapsuleFiles($before);

            if (File::exists($tempFile)) {
                File::delete($tempFile);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function capsuleFiles(): array
    {
        $capsuleDir = storage_path('app/osint_capsules');
        File::ensureDirectoryExists($capsuleDir);

        return array_map(
            fn ($file) => $file->getRealPath(),
            File::files($capsuleDir)
        );
    }

    /**
     * @param  list<string>  $before
     * @return list<string>
     */
    private function newDbFiles(array $before): array
    {
        return array_values(array_filter(
            $this->capsuleFiles(),
            fn ($path) => ! in_array($path, $before, true) && pathinfo($path, PATHINFO_EXTENSION) === 'db'
        ));
    }

    /**
     * @param  list<string>  $before
     */
    private function deleteNewCapsuleFiles(array $before): void
    {
        foreach ($this->capsuleFiles() as $path) {
            if (! in_array($path, $before, true) && in_array(pathinfo($path, PATHINFO_EXTENSION), ['csv', 'db', 'sql'], true)) {
                File::delete($path);
            }
        }
    }

    private function metaTotalLines(string $dbPath): int
    {
        $rows = $this->duckDbJson($dbPath, 'SELECT total_lines FROM meta LIMIT 1;');

        return (int) $rows[0]['total_lines'];
    }

    /**
     * @return list<string>
     */
    private function rawRows(string $dbPath): array
    {
        $rows = $this->duckDbJson($dbPath, 'SELECT raw_text FROM raw_data ORDER BY raw_text;');

        return array_map(fn ($row) => $row['raw_text'], $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function duckDbJson(string $dbPath, string $sql): array
    {
        $result = DuckDB::process(10)
            ->run([DuckDB::binary(), '-json', '-readonly', $dbPath, '-c', DuckDB::preamble().' '.$sql]);

        $this->assertTrue($result->successful(), $result->errorOutput());

        return json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
    }
}
