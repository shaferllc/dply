<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_php_opcache_profiles', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('server_id', 26);
            $table->string('php_version', 16);
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('memory_consumption_mb')->default(128);
            $table->unsignedInteger('interned_strings_buffer_mb')->default(16);
            $table->unsignedInteger('max_accelerated_files')->default(10000);
            $table->boolean('validate_timestamps')->default(true);
            $table->unsignedInteger('revalidate_freq')->default(2);
            $table->string('jit', 16)->default('off'); // off | tracing | function
            $table->unsignedInteger('jit_buffer_size_mb')->default(0);
            $table->text('extra_ini_raw')->nullable();
            $table->string('status', 32)->default('pending'); // pending|installing|active|failed
            $table->timestamp('last_applied_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            $table->unique(['server_id', 'php_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_php_opcache_profiles');
    }
};
