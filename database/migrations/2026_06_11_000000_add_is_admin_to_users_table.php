<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->index();
        });

        DB::table('users')
            ->where('email', 'test@example.com')
            ->update(['is_admin' => true]);

        if (! DB::table('users')->where('is_admin', true)->exists()) {
            $firstUserId = DB::table('users')->orderBy('id')->value('id');

            if ($firstUserId) {
                DB::table('users')
                    ->where('id', $firstUserId)
                    ->update(['is_admin' => true]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }
};
