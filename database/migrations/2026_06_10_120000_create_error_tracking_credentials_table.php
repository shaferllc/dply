<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('error_tracking_credentials', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Org-scoped so the whole team can reuse the project. Nullable for
            // personal (no-org) usage, mirroring log_drain_credentials.
            $table->foreignUlid('organization_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Provider slug: sentry | bugsnag | flare.
            $table->string('provider');
            $table->string('name');

            // Provider-specific secret stored as an encrypted JSON blob so one
            // column handles all provider shapes:
            //   sentry  → {dsn, traces_sample_rate?}
            //   bugsnag → {api_key}
            //   flare   → {key}
            $table->text('credentials');

            $table->timestamps();

            $table->index(['organization_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('error_tracking_credentials');
    }
};
