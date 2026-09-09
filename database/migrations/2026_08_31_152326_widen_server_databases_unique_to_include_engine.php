<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
        // The old uniqueness is not the same object everywhere. Laravel's
        // dropUnique() emits ALTER TABLE ... DROP CONSTRAINT, but on a database
        // restored from a dump the uniqueness comes back as a bare UNIQUE INDEX
        // with no matching constraint — and DROP CONSTRAINT then fails with
        // "constraint ... does not exist" while the index sits there enforcing
        // it. Production hit exactly that (seeded from a local dump), so drop
        // whichever form is present rather than assuming.
        self::dropLegacyUnique();

        Schema::table('server_databases', function (Blueprint $table): void {
            $table->unique(['server_id', 'engine', 'name'], 'server_databases_server_engine_name_unique');
        });
    }

    private static function dropLegacyUnique(): void
    {
        $name = 'server_databases_server_id_name_unique';

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            // Both are IF EXISTS, so whichever form this database happens to
            // use is removed and the other is a no-op.
            DB::statement('ALTER TABLE server_databases DROP CONSTRAINT IF EXISTS '.$name);
            DB::statement('DROP INDEX IF EXISTS '.$name);

            return;
        }

        // MySQL/SQLite only have the index form, which dropUnique handles.
        Schema::table('server_databases', function (Blueprint $table) use ($name): void {
            $table->dropUnique($name);
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
