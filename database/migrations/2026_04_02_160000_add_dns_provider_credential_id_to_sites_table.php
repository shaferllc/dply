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

        Schema::table('sites', function (Blueprint $table) {
            $table->foreign('dns_provider_credential_id')
                ->references('id')
                ->on('provider_credentials')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropForeign(['dns_provider_credential_id']);
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('dns_provider_credential_id');
        });
    }
};
