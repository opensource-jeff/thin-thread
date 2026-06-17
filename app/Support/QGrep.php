<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class QGrep
{
    /**
     * Get the qgrep binary path.
     */
    public static function binary(): string
    {
        return env('QGREP_BINARY', 'qgrep');
    }

    /**
     * Get a configured Process instance for qgrep.
     */
    public static function process(int $timeout = 60): \Illuminate\Process\PendingProcess
    {
        return Process::timeout($timeout);
    }

    /**
     * Get the path where leak files are stored for qgrep.
     */
    public static function storagePath(): string
    {
        return storage_path('app/osint_leaks');
    }

    /**
     * Get the path where qgrep index is stored.
     */
    public static function indexPath(): string
    {
        return storage_path('app/qgrep_index');
    }
}
