<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Teams could only ever be filled from people who were already organization
 * members — there was no way to invite someone straight onto a team. An
 * invitation now optionally carries the team it was sent from, and accepting
 * it joins the organization *and* that team in one step.
 *
 * Nullable on purpose: plain organization invites (the Members page) leave it
 * null, and nullOnDelete means deleting a team downgrades its pending invites
 * to ordinary org invites rather than dropping them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_invitations', function (Blueprint $table) {
            $table->foreignUlid('team_id')->nullable()->after('organization_id')
                ->constrained('teams')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('organization_invitations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('team_id');
        });
    }
};
