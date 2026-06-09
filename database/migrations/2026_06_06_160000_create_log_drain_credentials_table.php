<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('log_drain_credentials', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Org-scoped so the whole team can reuse the drain. Nullable for
            // personal (no-org) usage, mirroring object_storage_credentials.
            $table->foreignUlid('organization_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Drain provider slug: papertrail | logtail | syslog | dply_realtime.
            $table->string('provider');
            $table->string('name');

            // Provider-specific connection details stored as an encrypted JSON
            // blob so one column handles all provider shapes:
            //   papertrail  → {host, port}
            //   logtail     → {source_token}
            //   syslog      → {host, port}
            //   dply_realtime → {} (dply provides the endpoint)
            $table->text('credentials');

            $table->timestamps();

            $table->index(['organization_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('log_drain_credentials');
    }
};
