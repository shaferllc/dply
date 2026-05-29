<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parallels the Edge delivery columns: lets a serverless function record
 * whether it runs on dply's own managed FaaS account (`dply_serverless`) or
 * the customer's connected provider account (`org_digitalocean`). Managed
 * functions are billed cost-plus for usage; BYO functions keep the flat
 * management fee only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->string('serverless_backend')->nullable()->after('container_region');
            $table->foreignUlid('serverless_provider_credential_id')
                ->nullable()
                ->after('serverless_backend')
                ->constrained('provider_credentials')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('serverless_provider_credential_id');
            $table->dropColumn('serverless_backend');
        });
    }
};
