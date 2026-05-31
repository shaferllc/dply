<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_credential_shares', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('server_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            // Which credential set this link reveals (currently only the cache/redis AUTH block).
            $table->string('kind')->default('redis');
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at');
            $table->unsignedInteger('views_remaining')->default(1);
            $table->unsignedInteger('max_views')->default(1);
            $table->timestamps();

            $table->index(['server_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_credential_shares');
    }
};
