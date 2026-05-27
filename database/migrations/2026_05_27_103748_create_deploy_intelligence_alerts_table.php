<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proactive deploy/ops alerts surfaced by the deploy-intelligence
 * scanner. Each row is a finding the operator should act on — e.g.
 * "build N% slower than p50", "TLS expires in 7d", "env key X exists
 * in preview but not production".
 *
 * Idempotency: (organization_id, rule_key, signature) is unique so the
 * hourly scan can re-write the same finding without producing
 * duplicates. The scanner updates `last_observed_at` on each pass and
 * leaves `resolved_at` null until the condition clears or the operator
 * dismisses the alert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deploy_intelligence_alerts', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('organization_id', 26)->index();
            $table->string('rule_key', 64);
            $table->string('severity', 16)->default('info');
            // Stable identifier for the finding — typically the subject
            // ULID or "site:{id}:{detail}". Combined with rule_key to
            // dedupe within an org.
            $table->string('signature', 191);
            $table->string('subject_type', 255)->nullable();
            $table->char('subject_id', 26)->nullable();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('first_observed_at')->nullable();
            $table->timestamp('last_observed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->char('dismissed_by_user_id', 26)->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'rule_key', 'signature']);
            $table->index(['organization_id', 'resolved_at', 'last_observed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deploy_intelligence_alerts');
    }
};
