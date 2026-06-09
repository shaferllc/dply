<?php

declare(strict_types=1);

namespace App\Services\Imports\Handlers;

use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\ImportSiteMigration;
use App\Services\Imports\StepHandler;
use RuntimeException;

/**
 * Walks the migrated site snapshots (and a few server-level Ploi APIs) to
 * collect anything dply explicitly chose not to auto-migrate per Q16:
 * custom nginx blocks, server SSH keys, server-level cron entries (non-site),
 * Ploi recipes, Docker containers, backups configured. Items are persisted on
 * the parent ImportServerMigration; the migration progress page renders them
 * in a "Manual review" panel below the per-site status cards.
 *
 * v1 source of truth: data already in the source_snapshot JSON on each
 * ImportSiteMigration child plus a few extra driver calls when cheap. We
 * never block migration on collection failures — the worst case is the panel
 * is empty.
 */
class CollectManualReviewHandler implements StepHandler
{
    public static function key(): string
    {
        return ImportMigrationStep::KEY_COLLECT_MANUAL_REVIEW;
    }

    public function execute(ImportMigrationStep $step): void
    {
        $migration = ImportServerMigration::find($step->import_server_migration_id);
        if ($migration === null) {
            throw new RuntimeException('Parent migration missing for collect_manual_review.');
        }

        $items = [];

        // Per-site nginx_config blocks live on the source_snapshot. Pull
        // each child's snapshot and surface non-empty custom blocks.
        foreach ($migration->siteMigrations()->get() as $child) {
            $items = array_merge($items, $this->extractFromChild($child));
        }

        // Server-level items we don't have first-class Ploi-API methods for
        // yet (firewall rules, recipes, server cron, server SSH keys, docker
        // containers, backups) — leave structured placeholders so the panel
        // still lists "things you may want to recreate manually" even when
        // we don't have detail. Future driver expansions populate these
        // from real /servers/{server}/firewall etc. endpoints.
        $items[] = [
            'kind' => 'manual_advisory',
            'title' => 'Server-level firewall rules',
            'detail' => 'dply does not auto-import per-server firewall rules. Inspect your Ploi server\'s firewall and recreate on the dply server via the Firewall settings page.',
            'raw' => [],
            'dismissed_at' => null,
        ];
        $items[] = [
            'kind' => 'manual_advisory',
            'title' => 'Ploi recipes / one-shot scripts',
            'detail' => 'dply cannot replay opaque Ploi recipes. If you depended on a recipe (e.g. installed Redis Sentinel, custom packages), recreate that setup manually on the dply server.',
            'raw' => [],
            'dismissed_at' => null,
        ];
        $items[] = [
            'kind' => 'manual_advisory',
            'title' => 'Backups configured on Ploi',
            'detail' => 'Reconfigure backups on dply via Backups → Databases or Files settings. Existing Ploi backups stay on Ploi until you remove them.',
            'raw' => [],
            'dismissed_at' => null,
        ];

        $migration->manual_review_items = $items;
        $migration->save();

        $step->result_data = ['items_count' => count($items)];
        $step->save();
    }

    /**
     * @return list<array{kind: string, title: string, detail: string, raw: array<string, mixed>, dismissed_at: ?string}>
     */
    protected function extractFromChild(ImportSiteMigration $child): array
    {
        $items = [];
        $snapshot = $child->source_snapshot ?? [];

        $nginx = $snapshot['nginx_config'] ?? null;
        if (is_string($nginx) && trim($nginx) !== '') {
            $items[] = [
                'kind' => 'custom_nginx',
                'title' => 'Custom nginx config: '.$child->domain,
                'detail' => 'dply did not translate your custom nginx block. Copy the snippet into the site\'s nginx config in dply if you need it.',
                'raw' => ['nginx_config' => $nginx],
                'dismissed_at' => null,
            ];
        }

        $phpFpm = $snapshot['php_fpm_pool'] ?? null;
        if (is_array($phpFpm) && $phpFpm !== []) {
            $items[] = [
                'kind' => 'php_fpm_tuning',
                'title' => 'PHP-FPM pool tuning: '.$child->domain,
                'detail' => 'PHP-FPM pool settings detected on Ploi. dply uses its own defaults; recreate via the Webserver settings page if needed.',
                'raw' => ['php_fpm_pool' => $phpFpm],
                'dismissed_at' => null,
            ];
        }

        $opcache = $snapshot['opcache'] ?? null;
        if (is_array($opcache) && $opcache !== []) {
            $items[] = [
                'kind' => 'opcache_tuning',
                'title' => 'OPcache settings: '.$child->domain,
                'detail' => 'OPcache settings on Ploi differ from dply defaults. Apply via Server → PHP → OPcache profile if needed.',
                'raw' => ['opcache' => $opcache],
                'dismissed_at' => null,
            ];
        }

        return $items;
    }
}
