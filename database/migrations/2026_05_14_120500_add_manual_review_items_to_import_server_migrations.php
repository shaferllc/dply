<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores the server-level items that dply intentionally didn't auto-migrate
 * (custom nginx blocks, PHP-FPM tunings, OPcache settings, firewall rules,
 * server-level SSH keys, Ploi recipes that ran on the server, Docker
 * containers, Ploi-managed backups). Populated by CollectManualReviewHandler;
 * rendered on the migration progress page as the post-migration checklist.
 *
 * Per-item shape:
 *   { kind: 'custom_nginx' | 'firewall_rule' | 'recipe' | ..., title: string,
 *     detail: string, raw: array, dismissed_at: ?string }
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_server_migrations', function (Blueprint $table): void {
            $table->json('manual_review_items')->nullable()->after('failure_summary');
        });
    }

    public function down(): void
    {
        Schema::table('import_server_migrations', function (Blueprint $table): void {
            $table->dropColumn('manual_review_items');
        });
    }
};
