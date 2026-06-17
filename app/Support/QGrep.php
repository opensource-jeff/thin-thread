<?php

namespace App\Support;

use Illuminate\Support\Facades\Process;

class QGrep
{
    /**
     * Get the search command from .env.
     * Default to a standard qgrep search against the osint_leaks project.
     */
    public static function searchCommand(string $query): array
    {
        $command = env('QGREP_SEARCH_COMMAND', 'qgrep search osint_leaks {query}');
        
        // Replace the placeholder with the actual escaped query
        $finalCommand = str_replace('{query}', $query, $command);

        // Convert the string command into an array for Process::run
        // This is a simple split, assuming the command in .env is space-separated
        return explode(' ', $finalCommand);
    }

    /**
     * Get a configured Process instance for qgrep.
     */
    public static function process(int $timeout = 60): \Illuminate\Process\PendingProcess
    {
        return Process::timeout($timeout);
    }
}
