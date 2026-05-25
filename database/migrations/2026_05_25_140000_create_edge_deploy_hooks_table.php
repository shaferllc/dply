<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edge_deploy_hooks', function (Blueprint $table): void {
            // Per-site signed-URL hooks (P10b). Hitting the public
            // endpoint with the matching plaintext token triggers a
            // redeploy from the site's production branch — used by
            // Sanity/Contentful/etc. CMS integrations.
            $table->ulid('id')->primary();
            $table->ulid('site_id');
            $table->string('name', 80);
            // sha256 of the plaintext token; the URL itself is the
            // credential so we never store it in the clear.
            $table->string('token_hash', 64)->unique();
            // First 8 chars of the plaintext, shown in the UI so
            // operators can distinguish hooks at a glance.
            $table->string('token_prefix', 12);
            $table->ulid('created_by_user_id')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->ulid('last_triggered_deployment_id')->nullable();
            $table->timestamps();

            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['site_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edge_deploy_hooks');
    }
};
