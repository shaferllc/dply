<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_credentials', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Org-scoped so the whole team can reuse the mail transport keys.
            // Nullable for personal (no-org) usage, mirroring
            // log_drain_credentials / object_storage_credentials.
            $table->foreignUlid('organization_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Mail provider slug: smtp | mailgun | postmark | ses | resend.
            // (log carries no credentials, so it never persists a row here.)
            $table->string('provider');
            $table->string('name');

            // Provider-specific transport secret/connection stored as an
            // encrypted JSON blob so one column handles every provider shape:
            //   smtp     → {host, port, username, password, encryption}
            //   mailgun  → {secret, domain, endpoint}
            //   postmark → {token}
            //   ses      → {access_key_id, secret_access_key, region}
            //   resend   → {key}
            //
            // The from-address/name are NOT stored here — they are app identity
            // (per-site), entered each time on the binding, never in a reusable
            // org credential.
            $table->text('credentials');

            $table->timestamps();

            $table->index(['organization_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_credentials');
    }
};
