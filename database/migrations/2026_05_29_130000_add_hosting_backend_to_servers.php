<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records whether a server runs on the customer's own provider account (BYO,
 * the default) or on dply's own managed infrastructure (`dply_managed`). Managed
 * servers are provisioned with dply's platform credential and billed all-in
 * cost-plus instead of the per-server tier fee. Parallels Site.serverless_backend
 * / edge_backend.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            $table->string('hosting_backend')->nullable()->after('provider');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            $table->dropColumn('hosting_backend');
        });
    }
};
