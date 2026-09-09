<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Org-scoped write-never secrets (Laravel Cloud–style). Keys may collide;
 * identity is the ULID. A site may link at most one secret per key.
 *
 * @see docs/ORG_SHARED_SECRETS.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_secrets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('key');
            $table->text('value');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'key']);
        });

        Schema::create('organization_secret_sites', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('organization_secret_id')->constrained('organization_secrets')->cascadeOnDelete();
            $table->foreignUlid('site_id')->constrained('sites')->cascadeOnDelete();
            // Denormalized from the secret so one site cannot link two rows
            // that share a key. Secret keys are not renamed in v1.
            $table->string('key');
            $table->timestamps();

            $table->unique(['organization_secret_id', 'site_id']);
            $table->unique(['site_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_secret_sites');
        Schema::dropIfExists('organization_secrets');
    }
};
