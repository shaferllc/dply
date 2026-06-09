<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            // Set when the org redeems a beta invite. While non-null (and before
            // the global beta cutover) the org is a beta participant: dply's
            // platform fee is waived, trial/pause is suppressed, the beta caps
            // envelope applies, and the beta feature bundle is enabled. See
            // config/subscription.php `standard.beta` and BetaInvitation.
            $table->timestamp('beta_joined_at')->nullable()->after('trial_ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn('beta_joined_at');
        });
    }
};
