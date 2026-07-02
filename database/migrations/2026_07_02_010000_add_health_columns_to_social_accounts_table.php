<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            // Same health trio as git_provider_tokens: OAuth tokens expire and
            // get revoked exactly like PATs, and sites deploy through either.
            $table->timestamp('last_validated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->text('validation_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropColumn(['last_validated_at', 'expires_at', 'validation_error']);
        });
    }
};
