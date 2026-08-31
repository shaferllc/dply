<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `mysql.app` and `postgres.app` are different databases, but the original
     * unique key was (server_id, name) — so a server running both engines could
     * only ever have one of them tracked. Latent since the table was created;
     * the untracked-database scan is simply what surfaces it, by listing a real
     * database that adoption would then refuse to record.
     */
    public function up(): void
    {
        Schema::table('server_databases', function (Blueprint $table): void {
            $table->dropUnique('server_databases_server_id_name_unique');
            $table->unique(['server_id', 'engine', 'name'], 'server_databases_server_engine_name_unique');
        });
    }

    public function down(): void
    {
        Schema::table('server_databases', function (Blueprint $table): void {
            $table->dropUnique('server_databases_server_engine_name_unique');
            $table->unique(['server_id', 'name'], 'server_databases_server_id_name_unique');
        });
    }
};
