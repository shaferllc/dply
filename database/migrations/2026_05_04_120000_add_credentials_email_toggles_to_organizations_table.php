<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            // Both default to FALSE — emailing credentials is opt-in
            // because (a) credentials in mailboxes are a real attack
            // surface and (b) the existing dashboard already shows
            // them to authenticated users. Operators who actively
            // want the convenience can flip the toggle.
            $table->boolean('email_server_credentials_enabled')
                ->default(false)
                ->after('deploy_email_notifications_enabled');
            $table->boolean('email_database_credentials_enabled')
                ->default(false)
                ->after('email_server_credentials_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn([
                'email_server_credentials_enabled',
                'email_database_credentials_enabled',
            ]);
        });
    }
};
