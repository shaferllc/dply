<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Webserver templates: multi-engine + before/in/after.
 *
 * Why: a single `content` column meant every template was implicitly an
 * nginx server block. Real-world reverse-proxy config commonly has:
 *   - upstream / map / limit_req_zone blocks *before* `server { … }`
 *   - the server block itself
 *   - sometimes a sibling server block *after* for healthchecks or 80→443
 *
 * The new columns let operators put each chunk in the right slot while
 * keeping the existing `content` column as the in-server-block body
 * (where almost everything currently lives). `engine` defaults to 'nginx'
 * for backwards compatibility — existing rows continue to render as
 * nginx server blocks without any data backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webserver_templates', function (Blueprint $table): void {
            $table->string('engine', 32)->default('nginx')->after('label');
            $table->text('content_before')->nullable()->after('content');
            $table->text('content_after')->nullable()->after('content_before');
        });
    }

    public function down(): void
    {
        Schema::table('webserver_templates', function (Blueprint $table): void {
            $table->dropColumn(['engine', 'content_before', 'content_after']);
        });
    }
};
