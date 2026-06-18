<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            // Marks an org as internal/operator-owned (dply's own dogfood
            // control plane, self-managed/self-adopted orgs, staff orgs). While
            // true the org is permanently exempt from the trial/soft/hard-pause
            // ladder — trialState() short-circuits to NoTrial — so the platform
            // can never bill-pause itself. Distinct from beta (time-bounded,
            // globally gated) and from a far-future trial date (implicit/fragile).
            $table->boolean('is_internal')->default(false)->after('beta_joined_at');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn('is_internal');
        });
    }
};
