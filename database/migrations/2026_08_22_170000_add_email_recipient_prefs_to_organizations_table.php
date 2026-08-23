<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who receives each of the three org email defaults.
 *
 * The toggles beside this column only ever said *whether* to send. The
 * recipients were hardcoded per send-site and inconsistent: a deploy-finish
 * email fanned out to the site owner plus every owner and admin in the org,
 * while the SSH-details and database-password emails went to the creator
 * alone. Nothing surfaced that difference, and the deploy toggle's own label
 * claimed it notified "the deployer".
 *
 * One JSON blob rather than six columns because the shape is the same for each
 * key — {mode, user_ids} — and the set of keys will grow.
 *
 * Null means "the defaults", which are today's behaviour exactly: see
 * ManagesOrganizationEmailRecipients::EMAIL_RECIPIENT_DEFAULTS. Nothing changes
 * for an existing organization until someone edits it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            if (! Schema::hasColumn('organizations', 'email_recipient_prefs')) {
                $table->json('email_recipient_prefs')
                    ->nullable()
                    ->after('email_database_credentials_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            if (Schema::hasColumn('organizations', 'email_recipient_prefs')) {
                $table->dropColumn('email_recipient_prefs');
            }
        });
    }
};
