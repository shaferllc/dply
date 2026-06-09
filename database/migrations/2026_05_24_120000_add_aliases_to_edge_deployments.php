<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edge_deployments', function (Blueprint $table): void {
            // Stable per-deploy hostnames published into the KV host map at
            // publish time. Every successful deploy gets at least a
            // commit-SHA alias and a deploy-ULID alias so operators can
            // deep-link to any historical build without spinning up a
            // preview. Null on rows created before this migration; the
            // publisher backfills on next republish.
            $table->jsonb('aliases')->nullable()->after('cf_kv_version');
        });
    }

    public function down(): void
    {
        Schema::table('edge_deployments', function (Blueprint $table): void {
            $table->dropColumn('aliases');
        });
    }
};
