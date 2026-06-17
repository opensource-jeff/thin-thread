<?php

namespace App\Http\Controllers;

use App\Models\Leak;
use App\Support\QGrep;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
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
     * Stream search results from qgrep.
     */
    public function stream(Request $request)
    {
        set_time_limit(0);

        $query = trim((string) $request->input('q', ''));
        if ($query === '') {
            return response()->stream(function () {
                echo "event: close\ndata: no query\n\n";
            }, 200, ['Content-Type' => 'text/event-stream']);
        }

        $normalizedQuery = mb_strtolower($query, 'UTF-8');

        $indexDir = QGrep::indexPath();
        $indexFile = "{$indexDir}/leaks.qf";

        if (! File::exists($indexFile)) {
            Log::warning("Search failed: qgrep index not found at {$indexFile}");
            return response()->stream(function () {
                echo "event: done\ndata: index not ready\n\n";
            }, 200, ['Content-Type' => 'text/event-stream']);
        }

        Log::info("Search started: '{$query}' via qgrep.");

        return new StreamedResponse(function () use ($indexFile, $normalizedQuery) {
            // We use a regular expression for qgrep. We need to escape special characters if they are not intended for regex.
            // For OSINT, usually users want literal matches of emails/domains.
            $escapedQuery = preg_quote($normalizedQuery, '/');

            $process = QGrep::process(60)
                ->start([QGrep::binary(), 'search', $indexFile, $escapedQuery]);

            $output = method_exists($process, 'getOutputIterator')
                ? $process->getOutputIterator()
                : explode("\n", $process->wait()->output());

            foreach ($output as $line) {
                if (trim($line) === '') continue;

                // qgrep output: "path:text"
                $parts = explode(':', $line, 2);
                if (count($parts) < 2) continue;

                $fullPath = trim($parts[0]);
                $text = trim($parts[1]);

                if (!isset($metadataCache[$fullPath])) {
                    $leak = Leak::where('file_path', $fullPath)->first();
                    if ($leak) {
                        $metadataCache[$fullPath] = [
                            'display_name' => $leak->display_name,
                            'leak_date' => $leak->leak_date->format('Y-m-d'),
                            'total_lines' => (string) $leak->total_lines,
                            'capsule' => basename($fullPath),
                        ];
                        echo "event: meta\ndata: ".json_encode($metadataCache[$fullPath])."\n\n";
                    } else {
                        $metadataCache[$fullPath] = false;
                    }
                }

                if ($metadataCache[$fullPath]) {
                    $hit = preg_replace('/[[:cntrl:]]/', '', $text);
                    echo "event: hit\ndata: ".json_encode([
                        'capsule' => basename($fullPath),
                        'text' => $hit,
                    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n\n";
                }

                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
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
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
