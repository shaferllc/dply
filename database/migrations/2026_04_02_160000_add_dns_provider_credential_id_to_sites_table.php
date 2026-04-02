<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->ulid('dns_provider_credential_id')->nullable();
        });

        // SQLite cannot alter `sites` to add a foreign key when the table has a unique index on `project_id`
        // (Laravel would rebuild indexes and hit reserved names). Enforce in application code; use FK on Postgres/MySQL.
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('sites', function (Blueprint $table) {
                $table->foreign('dns_provider_credential_id')
                    ->references('id')
                    ->on('provider_credentials')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('sites', function (Blueprint $table) {
                $table->dropForeign(['dns_provider_credential_id']);
            });
        }

        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('dns_provider_credential_id');
        });
    }
};
