<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slack_installations', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Same morph shape as notification_channels: User | Organization | Team.
            // An install belongs to whoever owns the channels it will back, so a
            // personal channel and an org channel never share a bot token.
            $table->string('owner_type');
            $table->char('owner_id', 26);

            // Slack's own workspace identity (T…), not ours. Unique per owner so
            // re-running "Add to Slack" for the same workspace refreshes the token
            // in place instead of stacking duplicate installs.
            $table->string('team_id');
            $table->string('team_name');
            $table->string('bot_user_id')->nullable();

            // Space-separated scope list Slack actually granted — recorded so a
            // later scope addition can be detected and a re-auth prompted.
            $table->string('scopes', 1024)->nullable();

            // Encrypted JSON: {bot_token}.
            $table->text('credentials');

            $table->foreignUlid('installed_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['owner_type', 'owner_id', 'team_id']);
            $table->index(['owner_type', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slack_installations');
    }
};
