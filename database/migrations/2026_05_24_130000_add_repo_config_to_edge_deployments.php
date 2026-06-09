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
            // Frozen snapshot of dply.yaml (or dply.json) at build time.
            // Normalized into a single shape:
            //   {
            //     build: { command, output, root, node },
            //     redirects: [ { from, to, status } ],
            //     rewrites:  [ { from, to } ],
            //     headers:   [ { for, values: { name: value, ... } } ]
            //   }
            // Worker reads redirects/rewrites/headers from the KV host
            // map (sourced from this column) and applies them before the
            // R2 lookup. Null when the repo has no dply config file.
            $table->jsonb('repo_config')->nullable()->after('aliases');
        });
    }

    public function down(): void
    {
        Schema::table('edge_deployments', function (Blueprint $table): void {
            $table->dropColumn('repo_config');
        });
    }
};
