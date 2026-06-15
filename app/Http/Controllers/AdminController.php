<?php

namespace App\Http\Controllers;

use App\Support\DuckDB;
use App\Jobs\CreateOsintCapsule;
use App\Models\User;
use App\Support\CapsuleRetentionPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\Rule;
use JsonException;
use SplFileInfo;

class AdminController extends Controller
{
    /**
     * Display the admin dashboard.
     */
    public function index()
    {
        return view('admin', [
            'capsules' => $this->capsules(),
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
            $targetPath = storage_path('app/osint_capsules/'.$fileName);

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

        CreateOsintCapsule::dispatch(
            $filePath,
            $validated['display_name'],
            $validated['leak_date'],
            $validated['classification'],
            $validated['retention_policy']
        );

        return back()->with('status', "Ingestion job for '{$validated['display_name']}' has been dispatched safely with ".CapsuleRetentionPolicy::retentionDescription($validated['retention_policy']).'.');
    }

    /**
     * Delete a DuckDB capsule and local files tied to the same capsule UUID.
     */
    public function destroyCapsule(Request $request, string $capsule)
    {
        $path = $this->resolveCapsulePath($capsule);

        if ($path === null) {
            return back()->withErrors([
                'capsule' => 'The selected capsule could not be found.',
            ]);
        }

        $deletedFiles = $this->deleteCapsuleFiles($path);

        Log::warning('DuckDB capsule deleted by admin.', [
            'capsule' => basename($path),
            'deleted_files' => $deletedFiles,
            'admin_id' => $request->user()?->id,
        ]);

        return back()->with('status', 'Deleted capsule '.basename($path).' and its associated local metadata files.');
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
    private function capsules(): array
    {
        $capsuleDir = storage_path('app/osint_capsules');
        File::ensureDirectoryExists($capsuleDir);

        $capsules = [];

        foreach (File::files($capsuleDir) as $file) {
            if ($file->getExtension() !== 'db') {
                continue;
            }

            $metadata = $this->readCapsuleMetadata($file);

            $capsules[] = [
                'filename' => $file->getFilename(),
                'modified_at' => date('Y-m-d H:i:s', $file->getMTime()),
                'size' => $this->humanFileSize($file->getSize()),
                'metadata' => $metadata,
            ];
        }

        usort($capsules, fn ($left, $right) => strcmp($right['modified_at'], $left['modified_at']));

        return $capsules;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readCapsuleMetadata(SplFileInfo $file): ?array
    {
        $preamble = DuckDB::preamble();
        $sql = <<<SQL
{$preamble}
SELECT
    display_name,
    CAST(leak_date AS VARCHAR) AS leak_date,
    data_format,
    retention_policy,
    retention_label,
    CAST(retention_expires_at AS VARCHAR) AS retention_expires_at,
    CAST(ingested_at AS VARCHAR) AS ingested_at,
    total_lines
FROM meta
LIMIT 1;
SQL;

        $result = DuckDB::process(10)
            ->run([DuckDB::binary(), '-json', '-readonly', $file->getPathname(), '-c', $sql]);

        if (! $result->successful()) {
            return null;
        }

        try {
            $rows = json_decode($result->output(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return $rows[0] ?? null;
    }

    private function resolveCapsulePath(string $capsule): ?string
    {
        if (basename($capsule) !== $capsule || ! str_ends_with($capsule, '.db')) {
            return null;
        }

        $capsuleDir = storage_path('app/osint_capsules');
        File::ensureDirectoryExists($capsuleDir);

        $realDir = realpath($capsuleDir);
        $realPath = realpath($capsuleDir.DIRECTORY_SEPARATOR.$capsule);

        if ($realDir === false || $realPath === false) {
            return null;
        }

        if (! str_starts_with($realPath, $realDir.DIRECTORY_SEPARATOR)) {
            return null;
        }

        if (pathinfo($realPath, PATHINFO_EXTENSION) !== 'db') {
            return null;
        }

        return $realPath;
    }

    /**
     * @return list<string>
     */
    private function deleteCapsuleFiles(string $capsulePath): array
    {
        $capsuleDir = dirname($capsulePath);
        $filename = basename($capsulePath);
        $uuid = preg_replace('/^capsule_(.+)\.db$/', '$1', $filename);

        $candidatePaths = [
            $capsulePath,
            $capsulePath.'.wal',
            $capsulePath.'.tmp',
        ];

        if ($uuid !== $filename) {
            $candidatePaths[] = $capsuleDir.DIRECTORY_SEPARATOR."ingest_{$uuid}.sql";
            $candidatePaths[] = $capsuleDir.DIRECTORY_SEPARATOR."ingest_{$uuid}.csv";
        }

        $deletedFiles = [];

        foreach ($candidatePaths as $candidatePath) {
            if (File::exists($candidatePath)) {
                File::delete($candidatePath);
                $deletedFiles[] = basename($candidatePath);
            }
        }

        return $deletedFiles;
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

