<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_installations', function (Blueprint $table): void {
            // Same morph shape as notification_channels: User | Organization | Team.
            $table->ulid('id')->primary();
            $table->string('owner_type');
            $table->char('owner_id', 26);

            // Telegram chat ids are 64-bit and NEGATIVE for groups/supergroups
            // (e.g. -1001234567890), so string is the only safe column type here.
            $table->string('chat_id');
            $table->string('chat_title');

            // private | group | supergroup | channel — drives how the chat is
            // labelled, and whether "the bot must be an admin" advice applies.
            $table->string('chat_type')->default('group');

            $table->foreignUlid('connected_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['owner_type', 'owner_id', 'chat_id']);
            $table->index(['owner_type', 'owner_id']);
        });

        Schema::create('telegram_connect_tokens', function (Blueprint $table): void {
            // A short-lived claim check. Telegram has no OAuth and no redirect
            // back to us: the operator leaves for the Telegram app, and the only
            // thread tying their click to the incoming /start is this payload.
            $table->ulid('id')->primary();

            // Goes in the t.me deep link, so it must stay inside Telegram's
            // start-payload charset (A-Za-z0-9_-) and 64-char limit.
            $table->string('token', 64)->unique();

            $table->string('owner_type');
            $table->char('owner_id', 26);
            $table->foreignUlid('user_id')->nullable()->constrained('users')->cascadeOnDelete();

            // Set when a /start carrying this token arrives. Single-use: a leaked
            // link must not let a second chat attach itself to someone's account.
            $table->timestamp('consumed_at')->nullable();
            $table->foreignUlid('installation_id')->nullable();

            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['owner_type', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_connect_tokens');
        Schema::dropIfExists('telegram_installations');
    }
};
