<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discord_installations', function (Blueprint $table): void {
            // Same morph shape as notification_channels and slack_installations:
            // User | Organization | Team.
            $table->ulid('id')->primary();
            $table->string('owner_type');
            $table->char('owner_id', 26);

            // Discord's own guild ("server") identity — a snowflake, which is a
            // 64-bit int Discord always renders as a string. Stored as a string
            // for that reason; arithmetic on it is never wanted.
            $table->string('guild_id');
            $table->string('guild_name');

            // No credentials column, unlike slack_installations. Discord's bot
            // token belongs to the *application*, not the install — one token
            // from the developer portal serves every guild the bot joins. What
            // OAuth grants here is guild membership, not a secret.
            $table->string('permissions')->nullable();

            $table->foreignUlid('installed_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['owner_type', 'owner_id', 'guild_id']);
            $table->index(['owner_type', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discord_installations');
    }
};
