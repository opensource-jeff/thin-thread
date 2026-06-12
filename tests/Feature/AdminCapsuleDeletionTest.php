<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AdminCapsuleDeletionTest extends TestCase
{
    public function test_admin_capsule_delete_removes_capsule_and_associated_local_files(): void
    {
        $capsuleDir = storage_path('app/osint_capsules');
        File::ensureDirectoryExists($capsuleDir);

        $capsulePath = $capsuleDir.'/capsule_admin-delete-test.db';
        $walPath = $capsulePath.'.wal';
        $sqlPath = $capsuleDir.'/ingest_admin-delete-test.sql';
        $csvPath = $capsuleDir.'/ingest_admin-delete-test.csv';

        foreach ([$capsulePath, $walPath, $sqlPath, $csvPath] as $path) {
            File::put($path, 'placeholder');
        }

        try {
            $request = Request::create('/admin/capsules/capsule_admin-delete-test.db', 'DELETE');
            $request->setLaravelSession(app('session.store'));

            (new AdminController)->destroyCapsule($request, 'capsule_admin-delete-test.db');

            $this->assertFileDoesNotExist($capsulePath);
            $this->assertFileDoesNotExist($walPath);
            $this->assertFileDoesNotExist($sqlPath);
            $this->assertFileDoesNotExist($csvPath);
        } finally {
            foreach ([$capsulePath, $walPath, $sqlPath, $csvPath] as $path) {
                if (File::exists($path)) {
                    File::delete($path);
                }
            }
        }
    }
}
