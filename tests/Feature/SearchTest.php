<?php

namespace Tests\Feature;

use App\Models\Leak;
use App\Models\User;
use App\Support\QGrep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class SearchTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        File::ensureDirectoryExists(QGrep::storagePath());
        File::ensureDirectoryExists(QGrep::indexPath());
    }

    public function test_search_returns_hits_from_qgrep(): void
    {
        $this->withoutMiddleware();
        $user = User::factory()->create();
        
        $leakFile = QGrep::storagePath() . '/test_leak.txt';
        File::put($leakFile, "target@example.com\nother line\n");

        Leak::create([
            'display_name' => 'Test Leak',
            'file_path' => $leakFile,
            'leak_date' => '2026-06-12',
            'data_format' => 'UNSTRUCTURED',
            'retention_policy' => 'breach',
            'retention_label' => 'Breach',
            'ingested_at' => now(),
            'total_lines' => 2,
        ]);

        // Mock qgrep search
        // Since getOutputIterator is hard to mock with Process::fake(), 
        // we'll use a more direct approach in the controller or just mock the Support class if needed.
        // But for now, let's try to make Process::fake work by providing output.
        Process::fake([
            '*search*' => Process::result($leakFile . ":target@example.com\n", 0),
        ]);

        // Actually, the error was getOutputIterator on FakeInvokedProcess.
        // I'll change the controller to use a simpler way if possible, or fix the test to not use Process::fake for start() if it's problematic.
        // But let's try to fix the controller to be more testable.
        
        // Ensure index file exists for controller check
        File::put(QGrep::indexPath() . '/leaks.qf', 'fake index');

        $response = $this->actingAs($user)
            ->get('/search/stream?q=target@example.com');

        $response->assertStatus(200);
        
        // Streamed content might be empty if the fake process didn't yield anything to the iterator
        $content = $response->streamedContent();

        $this->assertStringContainsString('event: meta', $content);
        $this->assertStringContainsString('Test Leak', $content);
        $this->assertStringContainsString('event: hit', $content);
        $this->assertStringContainsString('target@example.com', $content);
        $this->assertStringContainsString('event: done', $content);

        File::delete($leakFile);
        File::delete(QGrep::indexPath() . '/leaks.qf');
    }
}
