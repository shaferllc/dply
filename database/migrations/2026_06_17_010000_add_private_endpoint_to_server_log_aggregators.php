<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records the aggregator's private (VPC) edge address alongside the public one so
 * same-network edges can ship over the private network — avoiding the public
 * internet and any provider cloud-firewall on the listen port. The edge installer
 * probes this first and falls back to the public endpoint when unreachable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_log_aggregators', function (Blueprint $table): void {
            $table->string('private_endpoint')->nullable()->after('endpoint');
        });
    }

    public function down(): void
    {
        Schema::table('server_log_aggregators', function (Blueprint $table): void {
            $table->dropColumn('private_endpoint');
        });
    }
};
