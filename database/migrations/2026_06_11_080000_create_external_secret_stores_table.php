<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A customer's OWN secret store (HashiCorp Vault / AWS Secrets Manager /
 * Doppler) that dply references but never copies values out of. A
 * {@see \App\Models\SiteSecretResidency} in external mode points at one of these
 * via store_id + a reference string; the value is fetched at deploy time.
 *
 *   resolution = dply  → dply fetches the value at push (it transiently sees it).
 *   resolution = onbox → dply only stages the reference; the server fetches it
 *                        with its own credentials (dply never sees it) — PR4.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_secret_stores', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            // vault | aws_sm | doppler
            $table->string('driver');
            $table->string('name');

            // Encrypted JSON connection config, driver-shaped:
            //   vault    → {endpoint, token, namespace?}
            //   aws_sm   → {region, key?, secret?}   (omit key/secret for onbox IAM)
            //   doppler  → {token, project?, config?}
            // May be empty for resolution=onbox stores that use the box's own IAM.
            $table->text('config')->nullable();

            // dply | onbox
            $table->string('resolution')->default('dply');

            $table->timestamps();

            $table->index(['organization_id', 'driver']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_secret_stores');
    }
};
