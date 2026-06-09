<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Warm server pool members. A member is a pre-provisioned spare (a real
 * {@see \App\Models\Server} owned by the system pool org) kept ready in a
 * provider×region×size×tier bucket, so a create can claim + personalize it
 * instead of cold-provisioning. See docs / the warm-pool plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_pool_members', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Bucket key — the dimensions that must be pre-created.
            $table->string('provider');
            $table->string('region');
            $table->string('size');
            // 'baseline' (OS+base+runtimes) or 'stack' (a popular full stack).
            $table->string('tier')->default('baseline');
            // For tier=stack: identifies which stack is pre-installed (so a claim
            // only matches a member whose stack equals the requested one).
            $table->string('stack_signature')->nullable();

            // The warm VM (nullable until the cloud resource exists).
            $table->foreignUlid('server_id')->nullable()->index();

            // warming → ready → claiming → claimed ; or retiring / failed.
            $table->string('status')->default('warming');
            $table->timestamp('health_checked_at')->nullable();

            // Claim bookkeeping.
            $table->foreignUlid('claimed_org_id')->nullable()->index();
            $table->timestamp('claimed_at')->nullable();

            $table->json('meta')->nullable();
            $table->timestamps();

            // Hot path: the autoscaler counts, and a claim finds, members by
            // bucket + status.
            $table->index(['provider', 'region', 'size', 'tier', 'status'], 'spm_bucket_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_pool_members');
    }
};
