<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Flip email_database_credentials_enabled to default true.
 *
 * This is the right default: operators creating a database expect to receive
 * the credentials by email — opting out is the intentional action, not opting
 * in. Existing orgs that explicitly set it to false are left alone (the UPDATE
 * only touches rows where the value is still the old false default, which in
 * practice means all existing orgs since the setting shipped as false).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Backfill all orgs to true before changing the column default so that
        // existing orgs get the improved behaviour.
        DB::table('organizations')->update(['email_database_credentials_enabled' => true]);

        Schema::table('organizations', function (Blueprint $table): void {
            $table->boolean('email_database_credentials_enabled')->default(true)->change();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->boolean('email_database_credentials_enabled')->default(false)->change();
        });
    }
};
