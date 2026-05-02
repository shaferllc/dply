<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sites can override which database engine they target on a multi-engine
 * server. Per the strategy memo: "Site database_engine defaults to
 * server's default; can be overridden to any engine installed on the
 * server."
 *
 * Nullable: when null, Site::databaseEngine() falls back to the server's
 * `is_default` ServerDatabaseEngine row, so existing single-engine
 * setups don't need this column populated.
 *
 * Bare string column rather than FK because the engine catalog is
 * referenced by name (postgres / mysql84 / mariadb / etc.) across the
 * codebase — same shape as the existing `database` field on
 * server.meta. Validity is enforced at the Site::databaseEngine() /
 * site-create form layer rather than via FK so a server can drop a
 * dependent engine without cascading site deletes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('database_engine', 32)->nullable()->after('runtime_version');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('database_engine');
        });
    }
};
