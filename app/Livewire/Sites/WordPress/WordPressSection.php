<?php

declare(strict_types=1);

namespace App\Livewire\Sites\WordPress;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\RemoteCliRun;
use App\Models\Site;
use App\Models\Snapshot;
use App\Modules\RemoteCli\Services\Kind;
use App\Modules\RemoteCli\Services\RemoteCliPermissionDeniedException;
use App\Modules\RemoteCli\Services\RemoteCliPermissions;
use App\Modules\RemoteCli\Services\RiskLevel;
use App\Modules\RemoteCli\Services\WpCli;
use App\Modules\Snapshots\Services\SnapshotDestinationFactory;
use App\Modules\Snapshots\Services\SnapshotService;
use App\Policies\SitePolicy;
use App\Services\WordPress\Advisories\AdvisoryProvider;
use App\Services\WordPress\PluginDirectory;
use App\Support\Servers\InstalledStack;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
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
 * {@see RemoteCliPermissions} gate (Q17), which uses
 * {@see SitePolicy} (view for Read, update for
 * anything else). Org membership is not treated as site-update.
 */
class WordPressSection extends Component
{
    use ConfirmsActionWithModal;
    use DispatchesToastNotifications;

    public Site $site;

    /** Active sub-tab. Persisted as ?wp= in the URL for sharing. */
    #[Url(as: 'wp')]
    public string $tab = 'console';

    public string $consoleCommand = 'plugin list';

    public string $consoleArgs = '--format=table';

    /** Most recent run id rendered in the Console output panel. */
    #[Locked]
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

    /** Slug typed into the "Install plugin" box (wp.org slug or zip URL host). */
    public string $pluginInstallSlug = '';

    /** Term for the WordPress.org directory search (debounced autocomplete). */
    public string $pluginSearch = '';

    /** @var list<array<string, mixed>> */
    public array $pluginSuggestions = [];

    /** @var list<array<string, mixed>> Popular/featured picks not already installed. */
    public array $pluginRecommendations = [];

    public string $pluginRecommendationList = 'popular';

    public bool $pluginRecommendationsLoaded = false;

    /** @var array<string, mixed>|null WordPress.org details for the plugin being considered. */
    public ?array $pluginDetail = null;

    /** Empty = latest. A pinned version doubles as rollback for an installed plugin. */
    public string $pluginDetailVersion = '';

    /** @var list<string> Installed plugin slugs ticked for a bulk action. */
    public array $selectedPlugins = [];

    /** Slug typed into the "Install theme" box. */
    public string $themeInstallSlug = '';

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

    // ── Tools tab ────────────────────────────────────────────────────────────
    /** Live wp maintenance-mode status; null until probed. */
    public ?bool $maintenanceActive = null;

    public string $searchReplaceFrom = '';

    public string $searchReplaceTo = '';

    /** Dry-run report from the last search-replace preview. */
    public ?string $searchReplacePreview = null;

    public ?string $permalinkStructure = null;

    public string $permalinkInput = '/%postname%/';

    // ── Database health ──────────────────────────────────────────────────────
    public ?array $dbHealth = null;

    // ── Core integrity ───────────────────────────────────────────────────────
    public ?string $checksumReport = null;

    // ── Cron events ──────────────────────────────────────────────────────────
    public array $cronEvents = [];

    public bool $cronEventsLoaded = false;

    /**
     * Reset user password, shown exactly once.
     *
     * Protected, not public: public Livewire properties are serialized into the
     * DOM snapshot and round-trip on every later request.
     */
    protected ?string $revealedUserPassword = null;

    protected ?string $revealedUserLogin = null;

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
        // drives the UI's enable/disable state, so a viewer never sees an
        // action button that the backend would reject.
        $permissions = app(RemoteCliPermissions::class);
        $user = auth()->user();

        return view('livewire.sites.wordpress.wordpress-section', [
            'history' => $this->history(),
            'latestRun' => $this->visibleLatestRun(),
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

        if ($wpcli->classifyRisk($command) !== RiskLevel::Read) {
            $this->authorize('update', $this->site);
        }

        try {
            $result = $wpcli->run(
                site: $this->site,
                command: $command,
                args: array_values(array_filter($args ?: [], fn ($a) => $a !== '')),
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
        $this->authorize('update', $this->site);

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
                // `wp plugin list --format=json` reports auto_update as on/off.
                // Dropped here, the row toggle would always render "off".
                'auto_update' => (string) ($row['auto_update'] ?? 'off'),
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
        $this->authorize('update', $this->site);

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
     * Per-row plugin lifecycle actions (mutating-recoverable — requires
     * site update). These queue async, so the table reflects the change
     * after a Refresh rather than instantly; the toast says as much.
     */
    public function activatePlugin(string $slug, WpCli $wpcli): void
    {
        $this->runWpAction($wpcli, 'plugin activate', $slug, 'plugins');
    }

    public function deactivatePlugin(string $slug, WpCli $wpcli): void
    {
        $this->runWpAction($wpcli, 'plugin deactivate', $slug, 'plugins');
    }

    public function updatePlugin(string $slug, WpCli $wpcli): void
    {
        $this->runWpAction($wpcli, 'plugin update', $slug, 'plugins');
    }

    /**
     * Install a plugin by wp.org slug and activate it in one step
     * (mutating-recoverable — requires site update). The slug box is
     * cleared on dispatch so the operator gets a clean field back.
     */
    public function installPlugin(WpCli $wpcli): void
    {
        $slug = trim($this->pluginInstallSlug);
        if (! $this->isValidSlug($slug)) {
            $this->addError('plugins', __('Enter a valid plugin slug (letters, numbers, dots, dashes).'));

            return;
        }

        $this->runWpAction($wpcli, 'plugin install', $slug, 'plugins', ['--activate']);
        $this->pluginInstallSlug = '';
    }

    /**
     * Destructive — opens the shared confirm modal before deleting. The
     * WpCli gate still enforces admin/owner at execution time; this is
     * the UI guardrail so a click can't nuke a plugin without intent.
     */
    public function confirmDeletePlugin(string $slug): void
    {
        if (! $this->isValidSlug($slug)) {
            $this->toastError(__('Invalid plugin name.'));

            return;
        }

        $this->openConfirmActionModal(
            method: 'deletePlugin',
            arguments: [$slug],
            title: __('Delete plugin?'),
            message: __('This permanently removes the plugin and its files. Deactivate instead if you only want to disable it — deletion cannot be undone from here.'),
            confirmLabel: __('Delete plugin'),
            destructive: true,
            details: [['label' => __('Plugin'), 'value' => $slug, 'mono' => true]],
        );
    }

    public function deletePlugin(string $slug, WpCli $wpcli): void
    {
        $this->runWpAction($wpcli, 'plugin delete', $slug, 'plugins');
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

        $this->themes = array_map(static fn (array $row): array => [
            'name' => (string) ($row['name'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'version' => (string) ($row['version'] ?? ''),
            'update' => (string) ($row['update'] ?? 'none'),
            // `wp theme list --format=json` reports auto_update as on/off.
            // Dropped here, the row toggle would always render "off".
            'auto_update' => (string) ($row['auto_update'] ?? 'off'),
        ], $rows);

        $this->themesLoaded = true;
    }

    public function activateTheme(string $slug, WpCli $wpcli): void
    {
        $this->runWpAction($wpcli, 'theme activate', $slug, 'themes');
    }

    public function updateTheme(string $slug, WpCli $wpcli): void
    {
        $this->runWpAction($wpcli, 'theme update', $slug, 'themes');
    }

    public function installTheme(WpCli $wpcli): void
    {
        $slug = trim($this->themeInstallSlug);
        if (! $this->isValidSlug($slug)) {
            $this->addError('themes', __('Enter a valid theme slug (letters, numbers, dots, dashes).'));

            return;
        }

        $this->runWpAction($wpcli, 'theme install', $slug, 'themes');
        $this->themeInstallSlug = '';
    }

    public function confirmDeleteTheme(string $slug): void
    {
        if (! $this->isValidSlug($slug)) {
            $this->toastError(__('Invalid theme name.'));

            return;
        }

        $this->openConfirmActionModal(
            method: 'deleteTheme',
            arguments: [$slug],
            title: __('Delete theme?'),
            message: __('This permanently removes the theme and its files. You cannot delete the active theme — activate another first. Deletion cannot be undone from here.'),
            confirmLabel: __('Delete theme'),
            destructive: true,
            details: [['label' => __('Theme'), 'value' => $slug, 'mono' => true]],
        );
    }

    public function deleteTheme(string $slug, WpCli $wpcli): void
    {
        $this->runWpAction($wpcli, 'theme delete', $slug, 'themes');
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

        $this->users = array_map(static fn (array $row): array => [
            'id' => (string) ($row['ID'] ?? $row['id'] ?? ''),
            'login' => (string) ($row['user_login'] ?? ''),
            'name' => (string) ($row['display_name'] ?? ''),
            'email' => (string) ($row['user_email'] ?? ''),
            'roles' => (string) ($row['roles'] ?? ''),
        ], $rows);

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
        $this->runWpAction($wpcli, 'core update', null, 'core');
    }

    /**
     * Run a mutating-recoverable wp-cli command (optionally scoped to a
     * single slug arg) and surface the outcome as a toast. Slugs are
     * validated before they reach the shell-escaped WpCli layer so a
     * crafted row name can't smuggle extra arguments.
     *
     * @param  list<string>  $extraArgs
     */
    private function runWpAction(WpCli $wpcli, string $command, ?string $slug, string $errorBag, array $extraArgs = []): void
    {
        $this->authorize('update', $this->site);

        $args = [];
        if ($slug !== null) {
            if (! $this->isValidSlug($slug)) {
                $this->toastError(__('Invalid item name.'));

                return;
            }
            $args = [$slug];
        }
        $args = array_merge($args, $extraArgs);

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
     * Console output for the run this request created. Viewers cannot
     * point {@see $latestRunId} at another row (Locked), and secret
     * Read stdout is masked even if an older unredacted row is shown.
     */
    private function visibleLatestRun(): ?RemoteCliRun
    {
        if ($this->latestRunId === null) {
            return null;
        }

        $run = RemoteCliRun::query()
            ->where('site_id', $this->site->id)
            ->where('kind', Kind::Wp)
            ->find($this->latestRunId);

        if ($run === null || ! is_string($run->stdout) || $run->stdout === '') {
            return $run;
        }

        $user = auth()->user();
        if ($user === null || $user->can('update', $this->site)) {
            return $run;
        }

        $run->stdout = app(WpCli::class)->redactStdoutForViewer(
            $this->site,
            $user,
            $run->command,
            $run->args ?? [],
            $run->stdout,
        );

        return $run;
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

    // ═════════════════════════════════════════════════════════════════════════
    // Tools, health and integrity.
    //
    // Every one of these routes through WpCli::run(), so risk classification,
    // the permission gate and the instant-vs-queued split are inherited rather
    // than re-decided here. Read-only probes land in WpCli's instantCommands()
    // allowlist and return inline; anything mutating queues and streams.
    // ═════════════════════════════════════════════════════════════════════════

    /** Shared plumbing so each feature below stays a few honest lines. */
    public function revealedUserPassword(): ?string
    {
        return $this->revealedUserPassword;
    }

    public function revealedUserLogin(): ?string
    {
        return $this->revealedUserLogin;
    }

    private function wp(WpCli $wpcli, string $command, array $args = [], bool $mutating = true, string $errorBag = 'tools'): ?string
    {
        if ($mutating) {
            $this->authorize('update', $this->site);
        }

        try {
            return trim($wpcli->run(
                site: $this->site,
                command: $command,
                args: $args,
                queuedBy: auth()->user(),
            )->stdout());
        } catch (RemoteCliPermissionDeniedException) {
            $this->addError($errorBag, __('Your role can\'t run that on this site.'));

            return null;
        } catch (\Throwable $e) {
            $this->addError($errorBag, $e->getMessage());

            return null;
        }
    }

    /** 1. Maintenance mode — the switch you want before touching a live site. */
    public function loadMaintenanceStatus(WpCli $wpcli): void
    {
        $out = $this->wp($wpcli, 'maintenance-mode status', [], mutating: false);
        if ($out === null) {
            return;
        }

        // wp prints "Maintenance mode is active." / "... not active."
        $this->maintenanceActive = str_contains(strtolower($out), 'not active') === false
            && str_contains(strtolower($out), 'active');
    }

    public function toggleMaintenanceMode(WpCli $wpcli): void
    {
        $enable = $this->maintenanceActive !== true;
        $this->wp($wpcli, 'maintenance-mode '.($enable ? 'activate' : 'deactivate'));
        $this->maintenanceActive = $enable;

        $this->toastSuccess($enable
            ? __('Maintenance mode on — visitors see the maintenance page.')
            : __('Maintenance mode off.'));
    }

    /**
     * 2. Search-replace, preview first.
     *
     * The single most destructive routine command in WordPress: it rewrites
     * serialized data across every table. Preview is a real --dry-run through
     * the same code path, so what you approve is what runs.
     */
    public function previewSearchReplace(WpCli $wpcli): void
    {
        if (trim($this->searchReplaceFrom) === '' || trim($this->searchReplaceTo) === '') {
            $this->addError('tools', __('Both the search and replace values are required.'));

            return;
        }

        $this->searchReplacePreview = $this->wp($wpcli, 'search-replace', [
            $this->searchReplaceFrom,
            $this->searchReplaceTo,
            '--dry-run',
            '--report-changed-only',
            '--precise',
        ], mutating: false);
    }

    public function confirmSearchReplace(): void
    {
        $this->authorize('update', $this->site);

        $this->openConfirmActionModal(
            'runSearchReplace',
            [],
            __('Rewrite URLs across the database'),
            __('Replaces every occurrence of :from with :to, including inside serialized data.', [
                'from' => $this->searchReplaceFrom,
                'to' => $this->searchReplaceTo,
            ]),
            __('Run replacement'),
            true,
            null,
            null,
            '',
            false,
            __('Take a snapshot first — this rewrites live content and cannot be undone from here.'),
        );
    }

    public function runSearchReplace(WpCli $wpcli): void
    {
        $this->wp($wpcli, 'search-replace', [
            $this->searchReplaceFrom,
            $this->searchReplaceTo,
            '--precise',
            '--report-changed-only',
        ]);

        $this->searchReplacePreview = null;
        $this->toastSuccess(__('Search and replace queued.'));
    }

    /** 3. Salt rotation — invalidates every session, the fix after a leak. */
    public function confirmRotateSalts(): void
    {
        $this->authorize('update', $this->site);

        $this->openConfirmActionModal(
            'rotateSalts',
            [],
            __('Rotate security keys'),
            __('Generates new WordPress salts. Everyone is signed out, including you, on the next request.'),
            __('Rotate salts'),
            true,
        );
    }

    public function rotateSalts(WpCli $wpcli): void
    {
        $this->wp($wpcli, 'config shuffle-salts');
        $this->toastSuccess(__('Salts rotated. All sessions are invalidated.'));
    }

    /** 4. Flush object cache and expired transients. */
    public function flushCaches(WpCli $wpcli): void
    {
        $this->wp($wpcli, 'cache flush');
        $this->wp($wpcli, 'transient delete', ['--all']);
        $this->toastSuccess(__('Object cache and transients flushed.'));
    }

    /** 5. Permalinks — reading the structure, and flushing rewrite rules. */
    public function loadPermalinks(WpCli $wpcli): void
    {
        $this->permalinkStructure = $this->wp($wpcli, 'option get', ['permalink_structure'], mutating: false);
        if (is_string($this->permalinkStructure) && trim($this->permalinkStructure) !== '') {
            $this->permalinkInput = trim($this->permalinkStructure);
        }
    }

    public function savePermalinks(WpCli $wpcli): void
    {
        $structure = trim($this->permalinkInput);
        if ($structure === '') {
            $this->addError('tools', __('Permalink structure cannot be empty.'));

            return;
        }

        $this->wp($wpcli, 'rewrite structure', [$structure, '--hard']);
        $this->permalinkStructure = $structure;
        $this->toastSuccess(__('Permalink structure updated and rewrite rules flushed.'));
    }

    public function flushRewrites(WpCli $wpcli): void
    {
        $this->wp($wpcli, 'rewrite flush', ['--hard']);
        $this->toastSuccess(__('Rewrite rules flushed.'));
    }

    /** 6. Database health: size, integrity check, then optimize/repair. */
    public function loadDbHealth(WpCli $wpcli): void
    {
        $size = $this->wp($wpcli, 'db size', ['--human-readable'], mutating: false);
        $check = $this->wp($wpcli, 'db check', [], mutating: false);

        $this->dbHealth = [
            'size' => $size,
            'check' => $check,
            // wp db check echoes "OK" per table; anything else is worth a look.
            'ok' => is_string($check) && ! preg_match('/\b(error|corrupt|crashed)\b/i', $check),
        ];
    }

    public function optimizeDatabase(WpCli $wpcli): void
    {
        $this->wp($wpcli, 'db optimize');
        $this->toastSuccess(__('Database optimize queued.'));
    }

    public function repairDatabase(WpCli $wpcli): void
    {
        $this->wp($wpcli, 'db repair');
        $this->toastSuccess(__('Database repair queued.'));
    }

    /**
     * 7. Core integrity — verify every core file against WordPress.org
     * checksums. The cheapest malware/tamper check there is.
     */
    public function verifyChecksums(WpCli $wpcli): void
    {
        $out = $this->wp($wpcli, 'core verify-checksums', [], mutating: false);
        $this->checksumReport = $out === null || $out === ''
            ? __('No output — the check queued; re-run once it finishes.')
            : $out;
    }

    /** 8. Auto-updates, per plugin and per theme. */
    public function togglePluginAutoUpdate(string $slug, bool $enable, WpCli $wpcli): void
    {
        $this->wp($wpcli, 'plugin auto-updates '.($enable ? 'enable' : 'disable'), [$slug]);
        $this->toastSuccess($enable
            ? __('Auto-updates enabled for :slug.', ['slug' => $slug])
            : __('Auto-updates disabled for :slug.', ['slug' => $slug]));
    }

    public function toggleThemeAutoUpdate(string $slug, bool $enable, WpCli $wpcli): void
    {
        $this->wp($wpcli, 'theme auto-updates '.($enable ? 'enable' : 'disable'), [$slug]);
        $this->toastSuccess($enable
            ? __('Auto-updates enabled for :slug.', ['slug' => $slug])
            : __('Auto-updates disabled for :slug.', ['slug' => $slug]));
    }

    /**
     * 9. Scheduled events. The Cron tab could switch the handler but never
     * showed what was actually scheduled — so a stuck job was invisible.
     */
    public function loadCronEvents(WpCli $wpcli): void
    {
        $out = $this->wp($wpcli, 'cron event list', ['--format=json'], mutating: false);
        $rows = is_string($out) && $out !== '' ? json_decode($out, associative: true) : [];

        $this->cronEvents = is_array($rows)
            ? array_values(array_filter($rows, 'is_array'))
            : [];
        $this->cronEventsLoaded = true;
    }

    public function runCronEvent(string $hook, WpCli $wpcli): void
    {
        $this->wp($wpcli, 'cron event run', [$hook]);
        $this->toastSuccess(__('Ran :hook.', ['hook' => $hook]));
    }

    /**
     * 10. User maintenance. The Users tab was read-only, so the two things an
     * operator actually needs — lock someone out, or get back in — meant
     * dropping to the console.
     */
    public function resetUserPassword(string $login, WpCli $wpcli): void
    {
        $password = Str::password(24, letters: true, numbers: true, symbols: false, spaces: false);

        $this->wp($wpcli, 'user update', [$login, '--user_pass='.$password, '--skip-email']);

        // Shown once, like every other generated credential in dply.
        $this->revealedUserPassword = $password;
        $this->revealedUserLogin = $login;
        $this->toastSuccess(__('Password reset for :login — copy it now.', ['login' => $login]));
    }

    public function changeUserRole(string $login, string $role, WpCli $wpcli): void
    {
        $allowed = ['administrator', 'editor', 'author', 'contributor', 'subscriber'];
        if (! in_array($role, $allowed, true)) {
            $this->addError('users', __('Unknown role.'));

            return;
        }

        $this->wp($wpcli, 'user set-role', [$login, $role]);
        $this->toastSuccess(__(':login is now :role.', ['login' => $login, 'role' => $role]));
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Plugin directory: search, recommendations, details, versions, bulk.
    //
    // Directory reads go to WordPress.org from the control plane, cached
    // globally (PluginDirectory) — typing in the search box costs nothing on the
    // customer's server. Only the install itself runs wp-cli on the box.
    // ═════════════════════════════════════════════════════════════════════════

    /** 1. Autocomplete, fired by the debounced search input. */
    public function updatedPluginSearch(): void
    {
        $term = trim($this->pluginSearch);

        $this->pluginSuggestions = mb_strlen($term) < 2
            ? []
            : $this->markInstalled(app(PluginDirectory::class)->search($term, 8));
    }

    /** 2. Recommendations: popular or featured, minus what is already installed. */
    public function loadPluginRecommendations(): void
    {
        $list = $this->pluginRecommendationList === 'featured' ? 'featured' : 'popular';

        // Fetch extra so hiding installed plugins still fills the row.
        $picks = array_filter(
            $this->markInstalled(app(PluginDirectory::class)->browse($list, 16)),
            static fn (array $p): bool => ! $p['installed'],
        );

        $this->pluginRecommendations = array_slice(array_values($picks), 0, 8);
        $this->pluginRecommendationsLoaded = true;
    }

    public function setPluginRecommendationList(string $list): void
    {
        $this->pluginRecommendationList = $list === 'featured' ? 'featured' : 'popular';
        $this->loadPluginRecommendations();
    }

    /**
     * 3. Details + compatibility before anything touches the site.
     *
     * wp.org accepts any install; WordPress then refuses to activate a plugin
     * whose declared WP/PHP minimums the site misses. Checking here turns a
     * confusing half-installed state into a clear "this won't run here".
     */
    public function showPluginDetail(string $slug, WpCli $wpcli): void
    {
        $info = app(PluginDirectory::class)->info(strtolower(trim($slug)));
        if ($info === null) {
            $this->addError('plugins', __('Could not load :slug from WordPress.org.', ['slug' => $slug]));

            return;
        }

        $info['installed'] = $this->isPluginInstalled((string) $info['slug']);
        $info['compatibility'] = PluginDirectory::compatibility(
            $info,
            $this->siteWordPressVersion($wpcli),
            $this->sitePhpVersion(),
        );

        $this->pluginDetail = $info;
        $this->pluginDetailVersion = '';
        $this->pluginSuggestions = [];
    }

    public function closePluginDetail(): void
    {
        $this->pluginDetail = null;
        $this->pluginDetailVersion = '';
    }

    /**
     * 4. Install from the detail card — latest, or a pinned version.
     *
     * A pinned version with --force is also how you roll back a bad update:
     * wp-cli replaces the files in place, settings and data untouched.
     */
    public function installFromDirectory(WpCli $wpcli): void
    {
        $slug = (string) ($this->pluginDetail['slug'] ?? '');
        if ($slug === '') {
            return;
        }

        $blockers = (array) ($this->pluginDetail['compatibility']['blockers'] ?? []);
        if ($blockers !== []) {
            $this->addError('plugins', implode(' ', $blockers));

            return;
        }

        $args = ['--activate'];
        $version = trim($this->pluginDetailVersion);
        if ($version !== '') {
            if (! in_array($version, (array) ($this->pluginDetail['versions'] ?? []), true)) {
                $this->addError('plugins', __('Pick a version from the list.'));

                return;
            }
            $args[] = '--version='.$version;
            $args[] = '--force';
        }

        $this->runWpAction($wpcli, 'plugin install', $slug, 'plugins', $args);

        $this->pluginDetail = null;
        $this->pluginDetailVersion = '';
        $this->pluginSearch = '';
    }

    /**
     * 5. One action across every ticked plugin, as ONE wp-cli call (wp-cli takes
     * many slugs) — one queued command and one audit entry, not N.
     *
     * Delete is deliberately absent: it is irreversible and already has a
     * per-row confirmation.
     */
    public function bulkPluginAction(string $action, WpCli $wpcli): void
    {
        $command = match ($action) {
            'activate' => 'plugin activate',
            'deactivate' => 'plugin deactivate',
            'update' => 'plugin update',
            'auto-on' => 'plugin auto-updates enable',
            'auto-off' => 'plugin auto-updates disable',
            default => null,
        };

        $slugs = array_values(array_filter(
            $this->selectedPlugins,
            fn (string $slug): bool => $this->isValidSlug($slug) && $this->isPluginInstalled($slug),
        ));

        if ($command === null || $slugs === []) {
            return;
        }

        if ($this->wp($wpcli, $command, $slugs, mutating: true, errorBag: 'plugins') === null) {
            return;
        }

        $this->selectedPlugins = [];
        $this->toastSuccess(trans_choice('{1} 1 plugin queued.|[2,*] :count plugins queued.', count($slugs), ['count' => count($slugs)]));
    }

    public function toggleSelectAllPlugins(): void
    {
        $all = array_map(static fn (array $p): string => (string) $p['name'], $this->plugins);
        $this->selectedPlugins = count($this->selectedPlugins) === count($all) ? [] : $all;
    }

    /**
     * @param  list<array<string, mixed>>  $plugins
     * @return list<array<string, mixed>>
     */
    private function markInstalled(array $plugins): array
    {
        return array_map(
            fn (array $p): array => $p + ['installed' => $this->isPluginInstalled((string) ($p['slug'] ?? ''))],
            $plugins,
        );
    }

    /** `wp plugin list` reports the slug as `name`. */
    private function isPluginInstalled(string $slug): bool
    {
        return $slug !== '' && collect($this->plugins)->contains('name', $slug);
    }

    /** Core tab's version when loaded, else one cheap instant `wp core version`. */
    private function siteWordPressVersion(WpCli $wpcli): ?string
    {
        if (is_string($this->core['version'] ?? null) && $this->core['version'] !== '') {
            return $this->core['version'];
        }

        try {
            $version = trim($wpcli->run($this->site, 'core version', [], auth()->user())->stdout());
        } catch (\Throwable) {
            return null;
        }

        return $version !== '' ? $version : null;
    }

    private function sitePhpVersion(): ?string
    {
        $server = $this->site->server;

        return $server !== null ? InstalledStack::fromMeta($server)->phpVersion : null;
    }
}
