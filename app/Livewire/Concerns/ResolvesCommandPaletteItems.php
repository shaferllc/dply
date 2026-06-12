<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Support\Docs\ContextualDocResolver;
use Illuminate\Support\Facades\Gate;
use Laravel\Pennant\Feature;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ResolvesCommandPaletteItems
{


    /** Best doc slug for a site when the palette wasn't opened on its page. */
    private function siteFallbackDocSlug(Site $site): ?string
    {
        try {
            return app(ContextualDocResolver::class)->resolveForSiteSection($site, 'general');
        } catch (\Throwable) {
            return null;
        }
    }

    /** Best doc slug for a server when the palette wasn't opened on its page. */
    private function serverFallbackDocSlug(): ?string
    {
        try {
            return app(ContextualDocResolver::class)->resolveForServerWorkspace(null);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<array{label: string, items: list<array<string, mixed>>}>
     */
    private function commandContext(string $key, ?Organization $org, string $query): array
    {
        $specs = $this->commandSpecs();
        $items = $this->resolveCommandItems($specs[$key] ?? [], $org, mb_strtolower(trim($query)), Gate::check('viewPlatformAdmin'));

        return [['label' => __(ucfirst($key)), 'items' => $items]];
    }

    /**
     * @return array<string, list<array{0: string, 1: string, 2: string, 3: string, 4?: array<string, mixed>, 5?: ?string, 6?: bool}>>
     */
    private function commandSpecs(): array
    {
        return [
            'create' => [
                ['New server', 'create add provision vm droplet host', 'servers.create', 'plus-circle'],
                ['New launch', 'create launch wizard stack', 'launches.create', 'plus-circle'],
                ['New cloud app', 'create cloud paas deploy', 'cloud.create', 'cube', [], 'surface.cloud'],
                ['New cloud database', 'create database postgres mysql redis', 'cloud.databases.create', 'circle-stack', [], 'surface.cloud'],
                ['New serverless function', 'create function faas', 'serverless.create', 'bolt', [], 'surface.serverless'],
                ['New edge app', 'create edge worker', 'edge.create', 'globe-alt', [], 'surface.edge'],
                ['New project', 'create project workspace', 'projects.index', 'rectangle-stack', [], 'surface.projects'],
                ['New organization', 'create organization team', 'organizations.create', 'building-office-2'],
                ['New script', 'create script automation', 'scripts.create', 'code-bracket', [], 'surface.scripts'],
                ['Import from DigitalOcean', 'import digitalocean do droplet', 'servers.import.digitalocean', 'cloud-arrow-down'],
                ['Import from Forge', 'import forge migrate', 'imports.forge.inventory', 'cloud-arrow-down'],
                ['Import from Ploi', 'import ploi migrate', 'imports.ploi.inventory', 'cloud-arrow-down'],
            ],
            'go' => [
                ['Dashboard', 'home overview', 'dashboard', 'squares-2x2'],
                ['Networking', 'firewall dns network load balancer', 'networking.index', 'share'],
                ['Infrastructure', 'infrastructure fleet overview', 'infrastructure.index', 'rectangle-group'],
                ['Fleet health', 'fleet health monitoring', 'fleet.health', 'heart', [], 'surface.fleet'],
                ['Cloud apps', 'cloud paas managed', 'cloud.index', 'cube', [], 'surface.cloud'],
                ['Cloud databases', 'database postgres mysql redis', 'cloud.databases.index', 'circle-stack', [], 'surface.cloud'],
                ['Serverless', 'functions faas', 'serverless.index', 'bolt', [], 'surface.serverless'],
                ['Edge', 'workers cdn edge', 'edge.index', 'globe-alt', [], 'surface.edge'],
                // ['Deploy sync', 'deploy groups sync', 'deploy-sync.index', 'arrows-right-left'],
                ['Scripts', 'scripts automation', 'scripts.index', 'code-bracket', [], 'surface.scripts'],
                ['Script marketplace', 'scripts marketplace presets', 'scripts.marketplace', 'rectangle-group', [], 'surface.scripts'],
                ['Marketplace', 'marketplace apps', 'marketplace.index', 'rectangle-group', [], 'surface.marketplace'],
                ['Backups — databases', 'backup database restore', 'backups.databases', 'circle-stack'],
                ['Backups — files', 'backup files restore', 'backups.files', 'document-text'],
                ['Status pages', 'status incident uptime', 'status-pages.index', 'document-text', [], 'surface.status_pages'],
                ['Notifications', 'inbox alerts', 'notifications.index', 'bell'],
            ],
            'settings' => [
                ['Profile', 'account preferences profile', 'settings.profile', 'user'],
                ['Security', 'password security', 'profile.security', 'shield-check'],
                ['Two-factor auth', '2fa mfa two factor authentication', 'two-factor.setup', 'shield-check'],
                ['SSH keys', 'ssh keys access', 'profile.ssh-keys', 'key'],
                ['API keys', 'api tokens keys', 'profile.api-keys', 'key'],
                ['CLI tokens', 'cli command line tokens', 'profile.cli', 'command-line'],
                ['Source control', 'github gitlab source control git', 'profile.source-control', 'code-bracket'],
                ['Notification channels', 'slack email webhook channels', 'profile.notification-channels', 'bell'],
                ['Backup configurations', 'backup config s3 storage', 'profile.backup-configurations', 'circle-stack'],
                ['Referrals', 'referral invite friends', 'profile.referrals', 'user'],
                ['Billing', 'billing invoices payment plan', 'billing.show', 'credit-card', ['org' => true]],
                ['Invoices', 'invoices billing receipts', 'billing.invoices', 'credit-card', ['org' => true]],
                ['Org members', 'organization members invite', 'organizations.members', 'building-office-2', ['org' => true]],
                ['Org teams', 'organization teams', 'organizations.teams', 'building-office-2', ['org' => true]],
            ],
            'admin' => [
                ['Admin overview', 'admin platform overview', 'admin.overview', 'wrench-screwdriver', [], null, true],
                ['Admin operations', 'admin operations ops', 'admin.operations', 'wrench-screwdriver', [], null, true],
                ['Audit log', 'admin audit log', 'admin.audit', 'document-text', [], null, true],
                ['Global feature flags', 'admin flags features', 'admin.flags.global', 'wrench-screwdriver', [], null, true],
                ['Beta invites', 'admin beta invites', 'admin.beta-invites', 'wrench-screwdriver', [], null, true],
                ['Platform organizations', 'admin organizations', 'admin.organizations.index', 'building-office-2', [], null, true],
            ],
        ];
    }

    /**
     * Filter + resolve a list of command specs into leaf items.
     *
     * @param  list<array<int, mixed>>  $commands
     * @return list<array{label: string, sublabel: ?string, url: string, icon: string}>
     */
    private function resolveCommandItems(array $commands, ?Organization $org, string $needle, bool $isAdmin): array
    {
        $items = [];
        foreach ($commands as $command) {
            [$label, $keywords, $routeName, $icon] = $command;
            $params = $command[4] ?? [];
            $feature = $command[5] ?? null;
            $adminOnly = $command[6] ?? false;

            if ($adminOnly && ! $isAdmin) {
                continue;
            }
            if ($feature !== null && ! Feature::active($feature)) {
                continue;
            }
            if ($needle !== '' && ! str_contains(mb_strtolower($label.' '.$keywords), $needle)) {
                continue;
            }

            $url = $this->resolveUrl($routeName, $params, $org);
            if ($url === null) {
                continue;
            }

            $items[] = ['label' => __($label), 'sublabel' => null, 'url' => $url, 'icon' => $icon];
        }

        return $items;
    }

    /**
     * Resolve a single resource's sub-pages into leaf items, scoped + filtered.
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: string}>  $pages
     * @param  array<int, mixed>  $routeParams
     * @return list<array{label: string, sublabel: ?string, url: string, icon: string}>
     */
    private function resolveResourcePages(array $pages, array $routeParams, string $query): array
    {
        $needle = mb_strtolower(trim($query));
        $items = [];
        foreach ($pages as [$label, $routeName, $icon, $keywords]) {
            if ($needle !== '' && ! str_contains(mb_strtolower($label.' '.$keywords), $needle)) {
                continue;
            }
            try {
                $url = route($routeName, $routeParams);
            } catch (\Throwable) {
                continue;
            }
            $items[] = ['label' => __($label), 'sublabel' => null, 'url' => $url, 'icon' => $icon];
        }

        return $items;
    }

    /**
     * A single "open the index" leaf, shown when the query is empty or matches.
     *
     * @return list<array{label: string, sublabel: ?string, url: string, icon: string}>
     */
    private function maybeIndexLink(string $query, string $haystack, string $label, string $sublabel, string $routeName, string $icon): array
    {
        $needle = mb_strtolower(trim($query));
        if ($needle !== '' && ! str_contains($haystack, $needle)) {
            return [];
        }
        $url = $this->resolveUrl($routeName, [], auth()->user()?->currentOrganization());

        return $url === null ? [] : [['label' => $label, 'sublabel' => $sublabel, 'url' => $url, 'icon' => $icon]];
    }

    /**
     * Resolve a command's URL, returning null when it can't be built — an
     * org-scoped route with no current org, or an unregistered route name.
     *
     * @param  array<string, mixed>  $params
     */
    private function resolveUrl(string $routeName, array $params, ?Organization $org): ?string
    {
        $routeParams = [];
        if (($params['org'] ?? false) === true) {
            if ($org === null) {
                return null;
            }
            $routeParams['organization'] = $org;
        }

        try {
            return route($routeName, $routeParams);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Org-scoped site lookup (eager-loads the server for deep links). */
    private function scopedSite(?Organization $org, ?string $id): ?Site
    {
        if ($org === null || $id === null) {
            return null;
        }

        return Site::query()
            ->whereIn('server_id', $org->serverIds())
            ->with('server')
            ->find($id);
    }

    /** Org-scoped server lookup. */
    private function scopedServer(?Organization $org, ?string $id): ?Server
    {
        if ($org === null || $id === null) {
            return null;
        }

        return $org->servers()->find($id);
    }

    /** Build an escaped LIKE pattern for a free-text query. */
    private function like(string $query): string
    {
        return '%'.str_replace(['%', '_'], ['\%', '\_'], trim($query)).'%';
    }
}
