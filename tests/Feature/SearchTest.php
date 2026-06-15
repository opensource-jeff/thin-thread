<?php

namespace Tests\Feature;

use App\Support\DuckDB;
use App\Http\Controllers\SearchController;
use App\Jobs\CreateOsintCapsule;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use ReflectionMethod;
use Tests\TestCase;

class SearchTest extends TestCase
{
    public function test_domain_search_requires_literal_domain_match(): void
    {
        $before = $this->capsuleFiles();
        $tempFile = tempnam(sys_get_temp_dir(), 'osint_search_test_');
        File::put($tempFile, "target@rare-domain.test\nadmin@example.test\n");

        try {
            (new CreateOsintCapsule(
                $tempFile,
                'Search Accuracy Test',
                '2026-06-12',
                'UNSTRUCTURED'
            ))->handle();

            $dbFiles = $this->newDbFiles($before);
            $this->assertCount(1, $dbFiles);

            $sql = $this->searchSqlFor('rare-domain.test');
            $rows = $this->duckDbJson($dbFiles[0], $sql);

            $this->assertSame(['target@rare-domain.test'], array_column($rows, 'raw_text'));
        } finally {
            $this->deleteNewCapsuleFiles($before);

            if (File::exists($tempFile)) {
                File::delete($tempFile);
            }
        }
    }

    private function searchSqlFor(string $query): string
    {
        $method = new ReflectionMethod(SearchController::class, 'buildSearchSql');
        $method->setAccessible(true);

        return $method->invoke(new SearchController, mb_strtolower($query, 'UTF-8'));
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
