<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Org-level defaults for Cloud-app alert destinations. Reused across
 * every site in the org so an operator wires up Slack once. Per-site
 * overrides live on site meta and supersede these when set.
 *
 * Org owners' login emails are always added to the recipient list at
 * runtime — these columns are purely the *extra* destinations the
 * operator wants notified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('alert_slack_webhook_url', 500)->nullable();
            // JSON list of additional email addresses to CC on alerts.
            $table->json('alert_extra_emails')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn(['alert_slack_webhook_url', 'alert_extra_emails']);
        });
    }
};
