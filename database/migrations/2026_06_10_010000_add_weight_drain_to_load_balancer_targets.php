<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-target weight + drain, so a load balancer can shift weighted traffic
 * (canary) and pull a backend from rotation (rolling). Additive: existing
 * targets default to weight 100, not drained — no behaviour change for the
 * generic LB feature. See docs/MULTI_BACKEND_SITES.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('load_balancer_targets', function (Blueprint $table): void {
            $table->unsignedSmallInteger('weight')->default(100)->after('status');
            $table->timestamp('drained_at')->nullable()->after('weight');
        });
    }

    public function down(): void
    {
        Schema::table('load_balancer_targets', function (Blueprint $table): void {
            $table->dropColumn(['weight', 'drained_at']);
        });
    }
};
