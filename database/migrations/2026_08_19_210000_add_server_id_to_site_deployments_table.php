<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record which server a deployment actually ran against.
 *
 * A site can be deployed to more than one box — its primary plus every
 * worker-pool replica — and the deployment row recorded only the site, so a
 * failed deploy gave no indication of WHICH machine failed. On a pool where one
 * member is broken and the others are fine, that is the first thing you need.
 *
 * Nullable and never backfilled: historical rows genuinely do not know, and
 * guessing (e.g. the site's current server) would attribute old deploys to a box
 * that may not have existed at the time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_deployments', function (Blueprint $table): void {
            $table->char('server_id', 26)->nullable()->after('site_id');
            $table->index(['server_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('site_deployments', function (Blueprint $table): void {
            $table->dropIndex(['server_id', 'created_at']);
            $table->dropColumn('server_id');
        });
    }
};
