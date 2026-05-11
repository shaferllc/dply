<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Snapshot of Linux accounts (UID >= 1000, no `nobody`) observed on each
     * server during the system-users sync. Lets the workspace show the last
     * known state without an SSH round-trip and gives the rest of the app a
     * queryable record of who's on the box.
     */
    public function up(): void
    {
        Schema::create('server_system_users', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('server_id')->constrained('servers')->cascadeOnDelete();
            $table->string('username', 64);
            $table->unsignedInteger('uid')->nullable();
            $table->string('home', 255)->default('');
            $table->string('shell', 255)->default('');
            $table->json('groups');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['server_id', 'username'], 'server_system_users_server_username_unq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_system_users');
    }
};
