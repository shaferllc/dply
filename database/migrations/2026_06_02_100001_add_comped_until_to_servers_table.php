<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            // When in the future, this server is comped (free) and excluded from
            // the managed-server bill — the localized "is this free?" primitive
            // used by the beta free-CX22 grant (set to the beta cutover at
            // provision), and reusable for support credits / partner deals.
            // Null on a beta org's managed box also reads as comped-until-cutover.
            $table->timestamp('comped_until')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            $table->dropColumn('comped_until');
        });
    }
};
