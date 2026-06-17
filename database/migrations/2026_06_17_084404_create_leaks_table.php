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
        Schema::create('leaks', function (Blueprint $table) {
            $table->id();
            $table->string('display_name');
            $table->string('file_path');
            $table->date('leak_date');
            $table->string('data_format');
            $table->string('retention_policy');
            $table->string('retention_label');
            $table->timestamp('retention_expires_at')->nullable();
            $table->timestamp('ingested_at');
            $table->bigInteger('total_lines');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leaks');
    }
};
