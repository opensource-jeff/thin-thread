<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('leaks', function (Blueprint $table) {
            $table->string('file_path', 512)->change();
            $table->index('file_path');
        });
    }

    public function down(): void
    {
        Schema::table('leaks', function (Blueprint $table) {
            $table->dropIndex(['file_path']);
            $table->string('file_path', 255)->change();
        });
    }
};
