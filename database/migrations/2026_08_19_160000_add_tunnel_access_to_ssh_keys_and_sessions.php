<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support for purpose-minted database-tunnel SSH access.
 *
 * `key_options` carries the authorized_keys options prefix (permitopen, no-pty,
 * …) separately from the key itself, so `public_key` stays a bare, parseable key
 * everywhere it is fingerprinted or deduped — smuggling the options inline would
 * break {@see \App\Services\Servers\SshPublicKeyFingerprint::forLine()}.
 *
 * `private_key` lets a minted key be handed to the operator exactly once, via the
 * install script, and never again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_authorized_keys', function (Blueprint $table): void {
            $table->string('key_options', 512)->nullable()->after('public_key');
        });

        Schema::table('server_ssh_sessions', function (Blueprint $table): void {
            $table->text('private_key')->nullable()->after('public_key_fingerprint');
            $table->timestamp('delivered_at')->nullable()->after('provisioned_at');
        });
    }

    public function down(): void
    {
        Schema::table('server_authorized_keys', function (Blueprint $table): void {
            $table->dropColumn('key_options');
        });

        Schema::table('server_ssh_sessions', function (Blueprint $table): void {
            $table->dropColumn(['private_key', 'delivered_at']);
        });
    }
};
