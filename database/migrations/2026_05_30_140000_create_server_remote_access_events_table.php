<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_remote_access_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('server_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('linux_user');
            $table->string('credential_role', 32);
            $table->string('source');
            $table->string('label');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedSmallInteger('command_count')->default(0);
            $table->boolean('failed')->default(false);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['server_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_remote_access_events');
    }
};
