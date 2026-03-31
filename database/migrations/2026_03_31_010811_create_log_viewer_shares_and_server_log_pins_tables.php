<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('log_viewer_shares', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('server_id')->constrained('servers')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->string('log_key');
            $table->mediumText('content');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['server_id', 'expires_at']);
        });

        Schema::create('server_log_pins', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('server_id')->constrained('servers')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('log_key');
            $table->string('line_fingerprint', 64);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['server_id', 'user_id', 'log_key', 'line_fingerprint'], 'server_log_pins_unique_line');
            $table->index(['server_id', 'log_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_log_pins');
        Schema::dropIfExists('log_viewer_shares');
    }
};
