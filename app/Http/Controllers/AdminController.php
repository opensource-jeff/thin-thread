<?php

namespace App\Http\Controllers;

use App\Models\Leak;
use App\Jobs\IngestLeakFile;
use App\Models\User;
use App\Support\CapsuleRetentionPolicy;
use App\Support\QGrep;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    /**
     * Display the admin dashboard.
     */
    public function index()
    {
        return view('admin', [
            'capsules' => $this->leaks(),
            'retentionPolicies' => CapsuleRetentionPolicy::options(),
            'users' => User::query()
                ->orderByDesc('is_admin')
                ->orderBy('name')
                ->get(),
        ]);
    }

    /**
     * Dispatch the background ingestion job.
     */
    public function ingest(Request $request)
    {
        $validated = $request->validate([
            'file_path' => 'nullable|string',
            'uploaded_file' => 'nullable|file',
            'display_name' => 'required|string',
            'leak_date' => 'required|date',
            'classification' => 'required|string',
            'retention_policy' => ['required', Rule::in(CapsuleRetentionPolicy::values())],
        ]);

        if (! $validated['file_path'] && ! $request->hasFile('uploaded_file')) {
            return back()->withErrors(['file_path' => 'Please provide a file path or upload a file.']);
        }

        $filePath = $validated['file_path'];

        if ($request->hasFile('uploaded_file')) {
            // Stream the file upload to prevent memory exhaustion
            $file = $request->file('uploaded_file');
            $fileName = uniqid('ingest_', true).'.txt';
            $leakDir = QGrep::storagePath();
            File::ensureDirectoryExists($leakDir);
            $targetPath = $leakDir.'/'.$fileName;

            $source = fopen($file->getRealPath(), 'rb');
            $destination = fopen($targetPath, 'wb');

            stream_copy_to_stream($source, $destination);

            fclose($source);
            fclose($destination);

            $filePath = $targetPath;
        } else {
            if (! File::exists($filePath)) {
                return back()->withErrors(['file_path' => 'The specified file path does not exist on the server.']);
            }
        }

        IngestLeakFile::dispatch(
            $filePath,
            $validated['display_name'],
            $validated['leak_date'],
            $validated['classification'],
            $validated['retention_policy']
        );

        return back()->with('status', "Ingestion job for '{$validated['display_name']}' has been dispatched safely with ".CapsuleRetentionPolicy::retentionDescription($validated['retention_policy']).'.');
    }

    /**
     * Delete a leak record and its associated text file.
     */
    public function destroyCapsule(Request $request, string $id)
    {
        $leak = Leak::find($id);

        if ($leak === null) {
            return back()->withErrors([
                'capsule' => 'The selected leak could not be found.',
            ]);
        }

        $path = $leak->file_path;
        $displayName = $leak->display_name;

        if (File::exists($path)) {
            File::delete($path);
        }

        $leak->delete();

        // Trigger qgrep re-indexing
        $this->updateQGrepIndex();

        Log::warning('Leak deleted by admin.', [
            'display_name' => $displayName,
            'file' => basename($path),
            'admin_id' => $request->user()?->id,
        ]);

        return back()->with('status', "Deleted leak '{$displayName}' and its associated file.");
    }

    private function updateQGrepIndex(): void
    {
        $indexDir = QGrep::indexPath();
        File::ensureDirectoryExists($indexDir);
        $indexFile = "{$indexDir}/leaks.qf";

        QGrep::process(300)
            ->run([QGrep::binary(), 'index', $indexFile, QGrep::storagePath()]);
    }

    /**
     * Create a user account.
     */
    public function storeUser(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'is_admin' => $request->boolean('is_admin'),
        ]);

        return back()->with('status', "Account for '{$validated['email']}' has been created.");
    }

    /**
     * Update a user account.
     */
    public function updateUser(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ]);

        $isAdmin = $request->boolean('is_admin');

        if ($request->user()->is($user) && ! $isAdmin) {
            return back()->withErrors([
                'account' => 'You cannot remove admin access from your current account.',
            ]);
        }

        if ($user->is_admin && ! $isAdmin && User::query()->where('is_admin', true)->count() <= 1) {
            return back()->withErrors([
                'account' => 'At least one admin account must remain.',
            ]);
        }

        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->is_admin = $isAdmin;

        if ($request->filled('password')) {
            $user->password = $validated['password'];
        }

        $user->save();

        return back()->with('status', "Account for '{$user->email}' has been updated.");
    }

    /**
     * Delete a user account.
     */
    public function destroyUser(Request $request, User $user)
    {
        if ($request->user()->is($user)) {
            return back()->withErrors([
                'account' => 'You cannot delete your current account.',
            ]);
        }

        if ($user->is_admin && User::query()->where('is_admin', true)->count() <= 1) {
            return back()->withErrors([
                'account' => 'At least one admin account must remain.',
            ]);
        }

        $email = $user->email;
        $user->delete();

        return back()->with('status', "Account for '{$email}' has been deleted.");
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function leaks(): array
    {
        $leaks = Leak::query()->orderByDesc('ingested_at')->get();

        return $leaks->map(function ($leak) {
            return [
                'id' => $leak->id,
                'filename' => basename($leak->file_path),
                'modified_at' => $leak->ingested_at->format('Y-m-d H:i:s'),
                'size' => File::exists($leak->file_path) ? $this->humanFileSize(File::size($leak->file_path)) : '0 B',
                'metadata' => [
                    'display_name' => $leak->display_name,
                    'leak_date' => $leak->leak_date->format('Y-m-d'),
                    'data_format' => $leak->data_format,
                    'retention_policy' => $leak->retention_policy,
                    'retention_label' => $leak->retention_label,
                    'retention_expires_at' => $leak->retention_expires_at?->format('Y-m-d H:i:s'),
                    'ingested_at' => $leak->ingested_at->format('Y-m-d H:i:s'),
                    'total_lines' => $leak->total_lines,
                ],
            ];
        })->toArray();
    }

    private function humanFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = (float) $bytes;
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return $unit === 0
            ? "{$bytes} {$units[$unit]}"
            : number_format($size, 1).' '.$units[$unit];
    }
}
