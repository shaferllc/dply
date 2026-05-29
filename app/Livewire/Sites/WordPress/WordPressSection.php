<?php

declare(strict_types=1);

namespace App\Livewire\Sites\WordPress;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\RemoteCliRun;
use App\Models\Site;
use App\Models\Snapshot;
use App\Services\RemoteCli\Kind;
use App\Services\RemoteCli\RemoteCliPermissionDeniedException;
use App\Services\RemoteCli\RemoteCliPermissions;
use App\Services\RemoteCli\RiskLevel;
use App\Services\RemoteCli\WpCli;
use App\Services\Snapshots\SnapshotDestinationFactory;
use App\Services\Snapshots\SnapshotService;
use App\Services\WordPress\Advisories\AdvisoryProvider;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Container for the WordPress Site Settings section (Q14).
 *
 * Renders one of five sub-tabs (Console / Plugins / Database / Cron /
 * Hardening). v1 ships with Console + Cron live; the other three are
 * placeholders gated on a v2 message until PR 10 fills them in.
 *
 * Permission checks delegate to {@see WpCli} via the underlying
 * {@see RemoteCliPermissions} gate (Q17), so
 * the same risk classification that drives the API layer also drives
 * the UI's enable/disable state.
 */
class WordPressSection extends Component
{
    use DispatchesToastNotifications;

    public Site $site;

    /** Active sub-tab. Persisted as ?wp= in the URL for sharing. */
    #[Url(as: 'wp')]
    public string $tab = 'console';

    public string $consoleCommand = 'plugin list';

    public string $consoleArgs = '--format=table';

    /** Most recent run id rendered in the Console output panel. */
    public ?int $latestRunId = null;

    /**
     * Plugins-tab cache. Populated by loadPlugins() from
     * `wp plugin list --format=json`. Each entry is enriched with
     * any open advisories from the AdvisoryProvider.
     *
     * @var list<array{name: string, status: string, version: string, update: string, advisories: list<array<string, mixed>>}>
     */
    public array $plugins = [];

    public bool $pluginsLoaded = false;

    /**
     * Themes-tab cache. Populated by loadThemes() from
     * `wp theme list --format=json`.
     *
     * @var list<array{name: string, status: string, version: string, update: string}>
     */
    public array $themes = [];

    public bool $themesLoaded = false;

    /**
     * Users-tab cache (read-only inspector). Populated by loadUsers()
     * from `wp user list --format=json`.
     *
     * @var list<array{id: string, login: string, name: string, email: string, roles: string}>
     */
    public array $users = [];

    public bool $usersLoaded = false;

    /**
     * Core-tab cache. Populated by loadCore() from `wp core version`
     * plus `wp core check-update`.
     *
     * @var array{version: ?string, update_available: bool, latest: ?string}|null
     */
    public ?array $core = null;

    public bool $coreLoaded = false;

    public function mount(Site $site): void
    {
        $this->authorize('view', $site);
        $this->site = $site;
        // Non-WordPress sites get a friendly "not detected" placeholder
        // (see view), matching how the Laravel section degrades when
        // Laravel isn't detected. No 404 — the operator may be navigating
        // around the site dashboard with no specific tab in mind.
    }

    public function render(): View
    {
        // The same risk classification that gates the WpCli service also
        // drives the UI's enable/disable state, so a member never sees an
        // action button that the backend would reject.
        $permissions = app(RemoteCliPermissions::class);
        $user = auth()->user();

        return view('livewire.sites.wordpress.wordpress-section', [
            'history' => $this->history(),
            'latestRun' => $this->latestRunId !== null ? RemoteCliRun::query()->find($this->latestRunId) : null,
            'snapshots' => $this->snapshots(),
            'canMutate' => $permissions->can($user, $this->site, RiskLevel::MutatingRecoverable),
            'canDestroy' => $permissions->can($user, $this->site, RiskLevel::Destructive),
        ]);
    }

    /**
     * Run a wp-cli command from the Console sub-tab. The args field is
     * a single-line string split on whitespace; for v1 that's enough
     * (operators who need quoted args use the CLI surface from PR 12).
     */
    public function runConsoleCommand(WpCli $wpcli): void
    {
        $command = trim($this->consoleCommand);
        if ($command === '') {
            $this->addError('consoleCommand', __('Enter a wp-cli command.'));

            return;
        }

        $args = $this->consoleArgs !== '' ? preg_split('/\s+/', trim($this->consoleArgs)) : [];

        try {
            $result = $wpcli->run(
                site: $this->site,
                command: $command,
                args: array_values(array_filter($args ?: [], fn ($a) => is_string($a) && $a !== '')),
                queuedBy: auth()->user(),
            );
        } catch (RemoteCliPermissionDeniedException $e) {
            $this->addError('consoleCommand', __('Your role can\'t run :risk commands. Ask an admin or owner.', [
                'risk' => $e->risk->value,
            ]));

            return;
        }

        $this->latestRunId = $result->run->id;
    }

    /**
     * Cron sub-tab "switch to system cron" action. Adds the
     * DISABLE_WP_CRON constant to wp-config.php (idempotent — wp config
     * set short-circuits if already present) and inserts a crontab
     * entry that runs `wp cron event run --due-now` every minute.
     *
     * Surfacing the inverse switch (back to wp-cron-via-HTTP) is the
     * delete-the-crontab + wp config delete; left to PR 10's hardening
     * tab to expose as a toggle.
     */
    public function switchToSystemCron(WpCli $wpcli): void
    {
        try {
            $wpcli->run(
                site: $this->site,
                command: 'config set',
                args: ['DISABLE_WP_CRON', 'true', '--raw', '--type=constant'],
                queuedBy: auth()->user(),
            );
        } catch (RemoteCliPermissionDeniedException $e) {
            $this->addError('cron', __('Admin or owner role required to switch cron handler.'));

            return;
        }

        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $meta['wp_cron'] = ['handler' => 'system_cron', 'switched_at' => now()->toISOString()];
        $this->site->meta = $meta;
        $this->site->save();
    }

    /**
     * Plugins sub-tab loader — runs `wp plugin list --format=json`
     * synchronously (the command is on the INSTANT allowlist) and
     * decorates each entry with any open advisories from the
     * AdvisoryProvider.
     */
    public function loadPlugins(WpCli $wpcli, AdvisoryProvider $advisories): void
    {
        try {
            $result = $wpcli->run(
                site: $this->site,
                command: 'plugin list',
                args: ['--format=json'],
                queuedBy: auth()->user(),
            );
        } catch (RemoteCliPermissionDeniedException $e) {
            $this->addError('plugins', __('Your role can\'t inspect plugins on this site.'));

            return;
        }

        $stdout = trim($result->stdout());
        $rows = $stdout !== '' ? json_decode($stdout, associative: true) : [];
        if (! is_array($rows)) {
            $this->addError('plugins', __('wp plugin list returned non-JSON output.'));
            $this->plugins = [];
            $this->pluginsLoaded = true;

            return;
        }

        $this->plugins = array_values(array_map(function (array $row) use ($advisories): array {
            $name = (string) ($row['name'] ?? '');
            $version = (string) ($row['version'] ?? '');
            $advisoryList = $name !== '' && $version !== ''
                ? $advisories->forPlugin($name, $version)
                : [];

            return [
                'name' => $name,
                'status' => (string) ($row['status'] ?? ''),
                'version' => $version,
                'update' => (string) ($row['update'] ?? 'none'),
                'advisories' => array_map(fn ($a) => [
                    'id' => $a->id,
                    'title' => $a->title,
                    'severity' => $a->severity,
                    'cve' => $a->cve,
                    'patched' => $a->patchedVersion,
                    'url' => $a->url,
                ], $advisoryList),
            ];
        }, array_filter($rows, 'is_array')));

        $this->pluginsLoaded = true;
    }

    /**
     * Bulk update everything reporting "available" — single
     * `wp plugin update --all` async dispatch (Q14 plugins sub-tab).
     */
    public function updateAllPlugins(WpCli $wpcli): void
    {
        try {
            $wpcli->run(
                site: $this->site,
                command: 'plugin update',
                args: ['--all'],
                queuedBy: auth()->user(),
            );
        } catch (RemoteCliPermissionDeniedException $e) {
            $this->addError('plugins', __('Updates require admin or owner role.'));

            return;
        }

        $this->toastSuccess(__('Queued: update all plugins. Refresh the list in a moment.'));
    }

    /**
     * Per-row plugin lifecycle actions (mutating-recoverable — any org
     * member). These queue async, so the table reflects the change after
     * a Refresh rather than instantly; the toast says as much.
     */
    public function activatePlugin(string $slug, WpCli $wpcli): void
    {
        $this->runRecoverable($wpcli, 'plugin activate', $slug, 'plugins');
    }

    public function deactivatePlugin(string $slug, WpCli $wpcli): void
    {
        $this->runRecoverable($wpcli, 'plugin deactivate', $slug, 'plugins');
    }

    public function updatePlugin(string $slug, WpCli $wpcli): void
    {
        $this->runRecoverable($wpcli, 'plugin update', $slug, 'plugins');
    }

    /**
     * Themes-tab loader — `wp theme list --format=json` (INSTANT, sync).
     */
    public function loadThemes(WpCli $wpcli): void
    {
        $this->resetErrorBag('themes');
        $rows = $this->readJsonRows($wpcli, 'theme list', ['--format=json'], 'themes');
        if ($rows === null) {
            $this->themes = [];
            $this->themesLoaded = true;

            return;
        }

        $this->themes = array_values(array_map(static fn (array $row): array => [
            'name' => (string) ($row['name'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'version' => (string) ($row['version'] ?? ''),
            'update' => (string) ($row['update'] ?? 'none'),
        ], $rows));

        $this->themesLoaded = true;
    }

    public function activateTheme(string $slug, WpCli $wpcli): void
    {
        $this->runRecoverable($wpcli, 'theme activate', $slug, 'themes');
    }

    public function updateTheme(string $slug, WpCli $wpcli): void
    {
        $this->runRecoverable($wpcli, 'theme update', $slug, 'themes');
    }

    /**
     * Users-tab loader — read-only inventory from `wp user list`
     * (INSTANT, sync). No mutating actions in v1; creating/deleting
     * users is deferred so this stays a safe inspector.
     */
    public function loadUsers(WpCli $wpcli): void
    {
        $this->resetErrorBag('users');
        $rows = $this->readJsonRows(
            $wpcli,
            'user list',
            ['--fields=ID,user_login,display_name,user_email,roles', '--format=json'],
            'users',
        );
        if ($rows === null) {
            $this->users = [];
            $this->usersLoaded = true;

            return;
        }

        $this->users = array_values(array_map(static fn (array $row): array => [
            'id' => (string) ($row['ID'] ?? $row['id'] ?? ''),
            'login' => (string) ($row['user_login'] ?? ''),
            'name' => (string) ($row['display_name'] ?? ''),
            'email' => (string) ($row['user_email'] ?? ''),
            'roles' => (string) ($row['roles'] ?? ''),
        ], $rows));

        $this->usersLoaded = true;
    }

    /**
     * Core-tab loader — installed version (`wp core version`) plus an
     * availability check (`wp core check-update`). Both are INSTANT.
     */
    public function loadCore(WpCli $wpcli): void
    {
        $this->resetErrorBag('core');

        try {
            $version = $wpcli->run($this->site, 'core version', [], auth()->user());
        } catch (RemoteCliPermissionDeniedException $e) {
            $this->addError('core', __('Your role can\'t inspect WordPress core on this site.'));
            $this->coreLoaded = true;

            return;
        } catch (\Throwable $e) {
            $this->addError('core', __('Could not reach the site over SSH: :err', ['err' => $e->getMessage()]));
            $this->coreLoaded = true;

            return;
        }

        $installed = trim($version->stdout()) ?: null;

        // check-update returns one JSON row per available update; an empty
        // array (or "Success: WordPress is at the latest version.") means
        // up to date.
        $updates = $this->readJsonRows($wpcli, 'core check-update', ['--format=json'], 'core') ?? [];
        $latest = null;
        foreach ($updates as $row) {
            if (isset($row['version'])) {
                $latest = (string) $row['version'];
                break;
            }
        }

        $this->core = [
            'version' => $installed,
            'update_available' => $updates !== [],
            'latest' => $latest,
        ];
        $this->coreLoaded = true;
    }

    public function updateCore(WpCli $wpcli): void
    {
        $this->runRecoverable($wpcli, 'core update', null, 'core');
    }

    /**
     * Run a mutating-recoverable wp-cli command (optionally scoped to a
     * single slug arg) and surface the outcome as a toast. Slugs are
     * validated before they reach the shell-escaped WpCli layer so a
     * crafted row name can't smuggle extra arguments.
     */
    private function runRecoverable(WpCli $wpcli, string $command, ?string $slug, string $errorBag): void
    {
        $args = [];
        if ($slug !== null) {
            if (! $this->isValidSlug($slug)) {
                $this->toastError(__('Invalid item name.'));

                return;
            }
            $args = [$slug];
        }

        try {
            $result = $wpcli->run($this->site, $command, $args, auth()->user());
        } catch (RemoteCliPermissionDeniedException $e) {
            $this->toastError(__('Your role can\'t run :risk commands. Ask an admin or owner.', [
                'risk' => $e->risk->value,
            ]));

            return;
        } catch (\Throwable $e) {
            $this->toastError(__('Command failed to start: :err', ['err' => $e->getMessage()]));

            return;
        }

        $label = trim('wp '.$command.($slug !== null ? ' '.$slug : ''));

        if ($result->isFailed()) {
            $this->toastError(__(':label failed (exit :code).', [
                'label' => $label,
                'code' => $result->exitCode() ?? '?',
            ]));

            return;
        }

        if ($result->isCompleted()) {
            $this->toastSuccess(__(':label completed.', ['label' => $label]));

            return;
        }

        $this->toastSuccess(__('Queued: :label. Refresh the list in a moment.', ['label' => $label]));
    }

    /**
     * Run an INSTANT read command and decode its JSON output into rows,
     * or null on permission denial / SSH failure / malformed output
     * (after setting an inline error on $errorBag).
     *
     * @param  list<string>  $args
     * @return list<array<string, mixed>>|null
     */
    private function readJsonRows(WpCli $wpcli, string $command, array $args, string $errorBag): ?array
    {
        try {
            $result = $wpcli->run($this->site, $command, $args, auth()->user());
        } catch (RemoteCliPermissionDeniedException $e) {
            $this->addError($errorBag, __('Your role can\'t inspect this on the site.'));

            return null;
        } catch (\Throwable $e) {
            $this->addError($errorBag, __('Could not reach the site over SSH: :err', ['err' => $e->getMessage()]));

            return null;
        }

        if ($result->isFailed()) {
            $message = trim($result->stderr());
            $this->addError($errorBag, $message !== '' ? $message : __('wp :command failed.', ['command' => $command]));

            return null;
        }

        $stdout = trim($result->stdout());
        $decoded = $stdout !== '' ? json_decode($stdout, associative: true) : [];
        if (! is_array($decoded)) {
            $this->addError($errorBag, __('wp :command returned unexpected output.', ['command' => $command]));

            return null;
        }

        return array_values(array_filter($decoded, 'is_array'));
    }

    private function isValidSlug(string $value): bool
    {
        return $value !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $value) === 1;
    }

    /**
     * Database sub-tab — take a fresh snapshot. Routes to the
     * preferred destination (S3 archive if configured, local-disk
     * fallback otherwise) so operators who set up an S3 bucket get
     * durable backups automatically without changing their click
     * pattern. Admin/owner only.
     */
    public function takeSnapshot(SnapshotService $snapshots, SnapshotDestinationFactory $destinations): void
    {
        $org = $this->site->organization;
        if ($org === null || ! $org->hasAdminAccess(auth()->user())) {
            $this->addError('snapshots', __('Admin or owner role required to take snapshots.'));

            return;
        }

        try {
            $snapshots->take(
                site: $this->site,
                destination: $destinations->preferred(),
                reason: Snapshot::REASON_MANUAL,
                userId: auth()->id(),
            );
        } catch (\Throwable $e) {
            $this->addError('snapshots', __('Snapshot failed: :err', ['err' => $e->getMessage()]));
        }
    }

    /**
     * Hardening sub-tab — flip a Q18 opinion on or off.
     *
     * Each opinion maps to a wp-cli call + a meta.scaffold.hardening
     * entry update. The meta is the single source of truth for the
     * UI; the wp-cli call is the side effect that makes the live site
     * actually match.
     */
    public function toggleHardening(string $opinionKey, WpCli $wpcli): void
    {
        $org = $this->site->organization;
        if ($org === null || ! $org->hasAdminAccess(auth()->user())) {
            $this->addError('hardening', __('Admin or owner role required to change hardening defaults.'));

            return;
        }

        $allowed = ['disallow_file_edit', 'force_ssl_admin', 'disable_wp_cron'];
        if (! in_array($opinionKey, $allowed, true)) {
            $this->addError('hardening', __('Unknown hardening opinion.'));

            return;
        }

        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $opinions = $meta['scaffold']['hardening'] ?? [];
        $current = collect($opinions)->firstWhere('key', $opinionKey);
        $newEnabled = ! ($current['enabled'] ?? false);

        $constant = match ($opinionKey) {
            'disallow_file_edit' => 'DISALLOW_FILE_EDIT',
            'force_ssl_admin' => 'FORCE_SSL_ADMIN',
            'disable_wp_cron' => 'DISABLE_WP_CRON',
        };

        try {
            if ($newEnabled) {
                $wpcli->run(
                    site: $this->site,
                    command: 'config set',
                    args: [$constant, 'true', '--raw', '--type=constant'],
                    queuedBy: auth()->user(),
                );
            } else {
                $wpcli->run(
                    site: $this->site,
                    command: 'config delete',
                    args: [$constant, '--type=constant'],
                    queuedBy: auth()->user(),
                );
            }
        } catch (RemoteCliPermissionDeniedException $e) {
            $this->addError('hardening', __('Permission denied: :err', ['err' => $e->getMessage()]));

            return;
        }

        // Upsert the opinion row in meta — set enabled flag, leave
        // unrelated keys untouched so PR 6's full set persists.
        $found = false;
        foreach ($opinions as &$row) {
            if (($row['key'] ?? null) === $opinionKey) {
                $row['enabled'] = $newEnabled;
                $found = true;
                break;
            }
        }
        unset($row);
        if (! $found) {
            $opinions[] = ['key' => $opinionKey, 'enabled' => $newEnabled];
        }
        $meta['scaffold']['hardening'] = $opinions;
        $this->site->meta = $meta;
        $this->site->save();
    }

    /**
     * @return Collection<int, Snapshot>
     */
    public function snapshots(): Collection
    {
        return Snapshot::query()
            ->where('site_id', $this->site->id)
            ->orderByDesc('created_at')
            ->limit(25)
            ->get();
    }

    /**
     * Last 25 wp-cli runs against this site, regardless of transport.
     *
     * @return Collection<int, RemoteCliRun>
     */
    private function history(): Collection
    {
        return RemoteCliRun::query()
            ->where('site_id', $this->site->id)
            ->where('kind', Kind::Wp)
            ->orderByDesc('created_at')
            ->limit(25)
            ->get();
    }
}
