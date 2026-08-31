<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_databases', function (Blueprint $table): void {
            // False when dply tracks a database it did not create and whose
            // password it therefore does not hold — an ADOPTED database found
            // by the server scan.
            //
            // A state, not a provenance flag: supplying or rotating the password
            // later flips this true while the row stays adopted, which an
            // `adopted_at`-style column could never express. Gates .env wiring,
            // the credential-share link, and the Connect panel's password handoff.
            // Admin-path operations (pg_dump/mysqldump as superuser, drop,
            // add-user, metrics) work regardless, since they authenticate with
            // the server's ServerDatabaseAdminCredential rather than this row's.
            //
            // Defaults true so every existing row — all of which dply created —
            // is untouched.
            $table->boolean('credentials_known')->default(true)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('server_databases', function (Blueprint $table): void {
            $table->dropColumn('credentials_known');
        });
    }
};
