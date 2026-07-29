<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('git_provider_tokens', function (Blueprint $table) {
            // Real expiration from the provider (GitHub sends it in the
            // github-authentication-token-expiration response header; GitLab
            // exposes it on /personal_access_tokens/self). Null = unknown or
            // non-expiring.
            $table->timestamp('expires_at')->nullable();
            // Last validation failure ("Bad credentials", …). Null = healthy
            // as of last_validated_at. Set/cleared by the daily health check.
            $table->text('validation_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('git_provider_tokens', function (Blueprint $table) {
            $table->dropColumn(['expires_at', 'validation_error']);
        });
    }
};
