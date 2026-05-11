<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds an optional free-text `comment` column to every routing table so the
 * operator can capture intent on each row ("this redirect handles Mailchimp's
 * broken URL", "EU CDN alias", etc.). Previously domains/redirects had no
 * such field; aliases/tenants had ad-hoc `label`/`notes` columns. This
 * unifies the convention.
 *
 * Tenants already have a `notes` column — see the next migration for the
 * notes→comment backfill, and the one after that for dropping `notes`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_domains', function (Blueprint $table): void {
            $table->text('comment')->nullable()->after('www_redirect');
        });
        Schema::table('site_domain_aliases', function (Blueprint $table): void {
            $table->text('comment')->nullable()->after('label');
        });
        Schema::table('site_redirects', function (Blueprint $table): void {
            $table->text('comment')->nullable()->after('response_headers');
        });
        Schema::table('site_tenant_domains', function (Blueprint $table): void {
            $table->text('comment')->nullable()->after('label');
        });
    }

    public function down(): void
    {
        Schema::table('site_domains', function (Blueprint $table): void {
            $table->dropColumn('comment');
        });
        Schema::table('site_domain_aliases', function (Blueprint $table): void {
            $table->dropColumn('comment');
        });
        Schema::table('site_redirects', function (Blueprint $table): void {
            $table->dropColumn('comment');
        });
        Schema::table('site_tenant_domains', function (Blueprint $table): void {
            $table->dropColumn('comment');
        });
    }
};
