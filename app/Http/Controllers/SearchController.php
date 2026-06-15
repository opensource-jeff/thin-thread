<?php

namespace App\Http\Controllers;

use App\Support\DuckDB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SearchController extends Controller
{
    /**
     * Display the search frontend.
     */
    public function index()
    {
        return view('search');
    }

    /**
     * Stream search results from all DuckDB capsules.
     */
    public function stream(Request $request)
    {
        $query = trim((string) $request->input('q', ''));
        if ($query === '') {
            return response()->stream(function () {
                echo "event: close\ndata: no query\n\n";
            }, 200, ['Content-Type' => 'text/event-stream']);
        }

        $normalizedQuery = mb_strtolower($query, 'UTF-8');

        $capsuleDir = storage_path('app/osint_capsules');
        if (! File::exists($capsuleDir)) {
            File::makeDirectory($capsuleDir, 0775, true);
        }

        $capsules = File::files($capsuleDir);
        $dbFiles = array_filter($capsules, fn ($file) => $file->getExtension() === 'db');

        Log::info("Search started: '{$query}' across ".count($dbFiles).' capsules.');

        $searchSql = $this->buildSearchSql($normalizedQuery);
        $fallbackSql = $this->buildExactSearchSql($normalizedQuery);

        return new StreamedResponse(function () use ($dbFiles, $searchSql, $fallbackSql) {
            foreach ($dbFiles as $dbFile) {
                $path = $dbFile->getRealPath();
                $filename = basename($path);

                echo "event: ping\ndata: ".json_encode(['capsule' => $filename])."\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();

                // 1. Fetch Metadata First
                $metaSql = DuckDB::preamble().' SELECT display_name, CAST(leak_date AS VARCHAR) as info1, CAST(total_lines AS VARCHAR) as info2 FROM meta LIMIT 1';
                $metaProcess = DuckDB::process(10)
                    ->run([DuckDB::binary(), '-json', '-readonly', $path, '-c', $metaSql]);

                $meta = null;
                if ($metaProcess->successful()) {
                    $metaOutput = json_decode($metaProcess->output(), true);
                    if (! empty($metaOutput[0])) {
                        $meta = [
                            'display_name' => $metaOutput[0]['display_name'],
                            'leak_date' => $metaOutput[0]['info1'],
                            'total_lines' => $metaOutput[0]['info2'],
                            'capsule' => $filename,
                        ];
                        echo "event: meta\ndata: ".json_encode($meta)."\n\n";
                        if (ob_get_level() > 0) {
                            ob_flush();
                        }
                        flush();
                    }
                }

                // 2. Stream Hits Individually
                $process = DuckDB::process(60)
                    ->run([DuckDB::binary(), '-json', '-readonly', $path, '-c', $searchSql]);

                if (! $process->successful() && $searchSql !== $fallbackSql) {
                    Log::warning("DuckDB FTS Search Failed on {$filename}; falling back to exact scan: ".$process->errorOutput());

                    $process = DuckDB::process(60)
                        ->run([DuckDB::binary(), '-json', '-readonly', $path, '-c', $fallbackSql]);
                }

                if ($process->successful()) {
                    $output = json_decode($process->output(), true);
                    if ($output) {
                        foreach ($output as $row) {
                            $hit = preg_replace('/[[:cntrl:]]/', '', $row['raw_text']);
                            echo "event: hit\ndata: ".json_encode([
                                'capsule' => $filename,
                                'text' => $hit,
                            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n\n";

                            if (ob_get_level() > 0) {
                                ob_flush();
                            }
                            flush();
                        }
                    }
                } else {
                    Log::error("DuckDB Search Failed on {$filename}: ".$process->errorOutput());
                }
            }
            echo "event: done\ndata: end\n\n";
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no', // Disable buffering for Nginx
        ]);
    }

    private function buildSearchSql(string $normalizedQuery): string
    {
        if ($this->requiresLiteralMatch($normalizedQuery)) {
            return $this->buildExactSearchSql($normalizedQuery);
        }

        $tokens = $this->searchTokens($normalizedQuery);
        if ($tokens === []) {
            return $this->buildExactSearchSql($normalizedQuery);
        }

        $ftsQuery = $this->sqlString(implode(' ', $tokens));
        $filters = $this->tokenFilters($tokens);
        $where = implode(' AND ', array_merge(['score IS NOT NULL'], $filters));
        $preamble = DuckDB::preamble();

        return <<<SQL
{$preamble}
LOAD fts;
SELECT raw_text FROM (
    SELECT raw_text, fts_main_raw_data.match_bm25(raw_text, {$ftsQuery}) AS score
    FROM raw_data
) WHERE {$where}
ORDER BY score DESC
LIMIT 25;
SQL;
    }

    private function buildExactSearchSql(string $normalizedQuery): string
    {
        if ($this->requiresLiteralMatch($normalizedQuery)) {
            $where = 'contains(raw_text, '.$this->sqlString($normalizedQuery).')';
        } else {
            $tokens = $this->searchTokens($normalizedQuery);
            $filters = $this->tokenFilters($tokens);
            $where = $filters === []
                ? 'contains(raw_text, '.$this->sqlString($normalizedQuery).')'
                : implode(' AND ', $filters);
        }

        $preamble = DuckDB::preamble();

        return <<<SQL
{$preamble}
SELECT raw_text
FROM raw_data
WHERE {$where}
LIMIT 25;
SQL;
    }

    /**
     * @return list<string>
     */
    private function searchTokens(string $query): array
    {
        if (preg_match_all('/[\p{L}\p{N}]+/u', $query, $matches) === false) {
            return [];
        }

        return array_values(array_unique(array_filter($matches[0], fn ($token) => $token !== '')));
    }

    /**
     * @param  list<string>  $tokens
     * @return list<string>
     */
    private function tokenFilters(array $tokens): array
    {
        return array_map(
            fn ($token) => 'contains(raw_text, '.$this->sqlString($token).')',
            $tokens
        );
    }

    private function requiresLiteralMatch(string $query): bool
    {
        return preg_match('/[^\p{L}\p{N}\s]/u', $query) === 1;
    }

    private function sqlString(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}

