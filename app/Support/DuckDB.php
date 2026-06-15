<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Log;

class DuckDB
{
    /**
     * Get the environment variables for DuckDB.
     *
     * @return array<string, string>
     */
    public static function environment(): array
    {
        $home = env('DUCKDB_HOME');

        if (!$home) {
            $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? null);

            if ((!$home || $home === '/') && function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
                $user = posix_getpwuid(posix_geteuid());
                $home = is_array($user) ? ($user['dir'] ?? null) : null;
            }
        }

        // Fallback to base_path() if HOME is missing or is root (common in restricted environments)
        if (!$home || $home === '/') {
            $home = base_path();
        }

        return ['HOME' => $home];
    }

    /**
     * Get the DuckDB binary path.
     */
    public static function binary(): string
    {
        return env('DUCKDB_BINARY', 'duckdb');
    }

    /**
     * Get a configured Process instance for DuckDB.
     */
    public static function process(int $timeout = 60): \Illuminate\Process\PendingProcess
    {
        return Process::timeout($timeout)
            ->env(self::environment());
    }

    /**
     * Get a SQL preamble for DuckDB, ensuring temp directory is set.
     */
    public static function preamble(): string
    {
        $tempDir = storage_path('app/duckdb_tmp');
        if (!File::exists($tempDir)) {
            File::makeDirectory($tempDir, 0775, true);
        }

        return "PRAGMA temp_directory='{$tempDir}';";
    }
}
