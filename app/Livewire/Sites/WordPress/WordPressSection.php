<?php

declare(strict_types=1);

namespace App\Livewire\Sites\WordPress;

use App\Jobs\SiteResetPermissionsJob;
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
use App\Modules\Snapshots\Jobs\TakeSiteSnapshotJob;
use App\Modules\WordPress\Jobs\SwitchWordPressCronHandlerJob;
use App\Modules\WordPress\Materializers\GitSourceMaterializerFactory;
use App\Policies\SitePolicy;
use App\Services\WordPress\Advisories\AdvisoryProvider;
use App\Services\WordPress\CoreReleases;
use App\Services\WordPress\PluginDirectory;
use App\Services\WordPress\ThemeDirectory;
use App\Support\Servers\InstalledStack;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use App\Models\ServerDatabase;
use App\Support\Servers\DatabaseConnectionTarget;
use App\Support\Servers\DatabaseConnectionTargetResolver;
use App\Support\Servers\DatabaseJumpHostAccess;
use App\Support\Servers\DatabaseWorkspaceEngines;
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

    /** Term for the WordPress.org theme search (debounced autocomplete). */
    public string $themeSearch = '';

    /** @var list<array<string, mixed>> */
    public array $themeSuggestions = [];

    /** @var list<array<string, mixed>> Popular/featured/new picks not already installed. */
    public array $themeRecommendations = [];

    public string $themeRecommendationList = 'popular';

    public bool $themeRecommendationsLoaded = false;

    /** @var array<string, mixed>|null WordPress.org details for the theme being considered. */
    public ?array $themeDetail = null;

    /** Empty = latest. A pinned version doubles as rollback for an installed theme. */
    public string $themeDetailVersion = '';

    /**
     * Off by default: activating swaps the live front end at once, and
     * customizer settings and menu locations are stored per theme.
     */
    public bool $themeDetailActivate = false;

    /** @var list<string> Installed theme slugs ticked for a bulk action. */
    public array $selectedThemes = [];

    /**
     * Themes-tab cache. Populated by loadThemes() from
     * `wp theme list --format=json`.
     *
     * @var list<array{name: string, title: string, status: string, version: string, update: string, auto_update: string}>
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

    // ── Users tab: filter, create, edit, delete ─────────────────────────────
    public string $userFilter = '';

    public string $userRoleFilter = '';

    public bool $showCreateUser = false;

    public string $newUserLogin = '';

    public string $newUserEmail = '';

    public string $newUserDisplayName = '';

    public string $newUserRole = 'editor';

    /** Let WordPress email the new user their account details too. */
    public bool $newUserSendEmail = false;

    public ?string $editingUserId = null;

    public string $editUserEmail = '';

    public string $editUserDisplayName = '';

    public ?string $deletingUserId = null;

    /** Who inherits the deleted user's posts and pages. */
    public string $deleteReassignTo = '';

    /** Login whose inline "set password" row is open. */
    public ?string $resettingPasswordLogin = null;

    /**
     * Typed password (blank = generate one). Public only so wire:model can bind
     * it; cleared the moment it's read so it stops riding the DOM snapshot.
     */
    public string $resetPasswordValue = '';

    /**
     * Core-tab cache. Populated by loadCore() from `wp core version`
     * plus `wp core check-update`.
     *
     * @var array{version: ?string, update_available: bool, latest: ?string, updates?: list<array{version: string, type: string}>, status?: ?string}|null
     */
    public ?array $core = null;

    public bool $coreLoaded = false;

    /** Version-picker target: one of CoreReleases::branches(). */
    public string $coreTargetVersion = '';

    /** @var list<array{version: string, php: string, mysql: string}> */
    public array $coreBranches = [];

    /**
     * WP_AUTO_UPDATE_CORE as read from wp-config: 'default' (constant absent —
     * WordPress's own minor-only behaviour), 'off', 'minor' or 'all'. Null
     * until read, or when the read failed.
     */
    public ?string $coreAutoUpdate = null;

    /** @var list<array{code: string, name: string, native: string, status: string}> */
    public array $coreLanguages = [];

    public bool $coreLanguagesLoaded = false;

    public string $coreLanguageInstall = '';

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

    /** @var list<array{name: string, bytes: int}>|null Tables, biggest first; null until loaded. */
    public ?array $dbTables = null;

    /** @var array{total: int, count: int, top: list<array{name: string, bytes: int}>}|null */
    public ?array $autoloadAudit = null;

    // ── Core integrity ───────────────────────────────────────────────────────
    public ?string $checksumReport = null;

    // ── Cron events ──────────────────────────────────────────────────────────
    public array $cronEvents = [];

    public bool $cronEventsLoaded = false;

    public string $cronEventFilter = '';

    // ── Tools: site settings ─────────────────────────────────────────────────
    /** @var array{blogname: ?string, blogdescription: ?string, blog_public: ?string, home: ?string, siteurl: ?string, debug: ?bool}|null */
    public ?array $siteSettings = null;

    public string $settingsTitle = '';

    public string $settingsTagline = '';

    public string $settingsHome = '';

    public string $settingsSiteurl = '';

    // ── Hardening ────────────────────────────────────────────────────────────
    /** @var list<array{key: string, label: string, status: string, detail: string}>|null */
    public ?array $securityScan = null;

    /**
     * Reset user password, shown exactly once.
     *
     * Protected, not public: public Livewire properties are serialized into the
     * DOM snapshot and round-trip on every later request.
     */
    protected ?string $revealedUserPassword = null;

    protected ?string $revealedUserLogin = null;

    private const WP_ROLES = ['administrator', 'editor', 'author', 'contributor', 'subscriber'];

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
            // Narrower than isWordPressDetected() on purpose: a git-deployed WordPress
            // would have its cloned themes overwritten by the next deploy.
            'gitSourcesSupported' => app(GitSourceMaterializerFactory::class)->supports($this->site),
            'coreManagedByComposer' => $this->coreManagedByComposer(),
            'dbRemote' => $this->tab === 'database' ? $this->remoteDatabaseAccess() : null,
        ]);
    }

    /**
     * Connection facts + an `ssh -L` tunnel for the site's own database.
     *
     * Read-only on purpose: the WordPress database has no `database` binding,
     * so the binding-keyed Connect panel can't address it, and adopting one
     * would take over DB_* at deploy. Null (no card) when nothing resolves.
     *
     * @return array{target: DatabaseConnectionTarget, ssh: string, tunnel: ?array<string, mixed>}|null
     */
    private function remoteDatabaseAccess(): ?array
    {
        $server = $this->site->server;
        if ($server === null) {
            return null;
        }

        $databases = ServerDatabase::query()->where('server_id', $server->id);
        $id = data_get($this->site->meta, 'scaffold.database.server_database_id');
        $db = filled($id) ? (clone $databases)->find($id) : null;
        // Not scaffolded by dply: fall back to the name the pipeline derives.
        $db ??= (clone $databases)->where('name', 'dply_'.Str::slug($this->site->slug, '_'))->first();

        if (! $db instanceof ServerDatabase || DatabaseWorkspaceEngines::family((string) $db->engine) === 'sqlite') {
            return null;
        }

        // defaultPort(), not DatabaseConnectionTarget::defaultPortFor(): engines
        // are versioned ids (mysql84), which the latter maps to 5432.
        $target = DatabaseConnectionTarget::fromServerDatabase($db, '127.0.0.1', $db->defaultPort());
        $reason = app(DatabaseConnectionTargetResolver::class)->tunnelUnavailableReason($target, $server);

        return [
            'target' => $target,
            'ssh' => DatabaseJumpHostAccess::sshUserFor($server).'@'.$server->ip_address,
            'tunnel' => $reason === null
                ? DatabaseJumpHostAccess::tunnelCommandsFor($target, $server, DatabaseJumpHostAccess::BASE_LOCAL_PORT)
                : null,
            // One click into TablePlus once that tunnel is up. The link carries
            // no secret — the controller reads the password server-side.
            'openLink' => $reason === null && $db->hasUsableCredentials()
                ? url()->temporarySignedRoute('sites.databases.server-connect-link', now()->addMinutes(30), [
                    'server' => $server->id,
                    'site' => $this->site->id,
                    'database' => $db->id,
                ])
                : null,
        ];
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
    public function switchToSystemCron(): void
    {
        $this->switchCronHandler('system');
    }

    public function switchToWpCron(): void
    {
        $this->switchCronHandler('wp-cron');
    }

    /**
     * Queued: installing the crontab entry is SSH work, and the job orders the
     * entry and DISABLE_WP_CRON so the site is never left with neither.
     */
    private function switchCronHandler(string $to): void
    {
        $this->authorize('update', $this->site);

        // `config set` / `config delete` run at the Destructive tier.
        if (! $this->canDestroyHere()) {
            $this->addError('cron', __('Admin or owner role required to switch cron handler.'));

            return;
        }
        if ($this->site->server === null) {
            return;
        }

        SwitchWordPressCronHandlerJob::dispatch((string) $this->site->id, $to, (string) auth()->id());

        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $meta['wp_cron'] = array_merge(is_array($meta['wp_cron'] ?? null) ? $meta['wp_cron'] : [], ['switching_to' => $to, 'error' => null]);
        $this->site->meta = $meta;
        $this->site->save();

        $this->toastSuccess($to === 'system'
            ? __('Switching to system cron — installing the crontab entry first.')
            : __('Switching back to wp-cron.'));
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
        // `title` names child themes after their parent's real display name.
        $rows = $this->readJsonRows($wpcli, 'theme list', ['--fields=name,title,status,version,update,auto_update', '--format=json'], 'themes');
        if ($rows === null) {
            $this->themes = [];
            $this->themesLoaded = true;

            return;
        }

        $this->themes = array_map(static fn (array $row): array => [
            'name' => (string) ($row['name'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
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

        // One row per available release; "minor" rows are the security and
        // maintenance path within the installed branch.
        $rows = array_values(array_filter(array_map(static fn (array $row): array => [
            'version' => (string) ($row['version'] ?? ''),
            'type' => (string) ($row['update_type'] ?? ''),
        ], $updates), static fn (array $row): bool => $row['version'] !== ''));
        usort($rows, static fn (array $a, array $b): int => version_compare($b['version'], $a['version']));

        $releases = app(CoreReleases::class);
        $this->core = [
            'version' => $installed,
            'update_available' => $rows !== [],
            'latest' => $rows[0]['version'] ?? null,
            'updates' => $rows,
            'status' => $releases->status($installed),
        ];
        $this->coreBranches = $releases->branches();
        $this->coreLoaded = true;
    }

    public function updateCore(WpCli $wpcli): void
    {
        if ($this->refuseComposerCore()) {
            return;
        }

        $this->runWpAction($wpcli, 'core update', null, 'core');
    }

    /** Stay on the installed branch: security and maintenance releases only. */
    public function updateCoreMinor(WpCli $wpcli): void
    {
        if ($this->refuseComposerCore()) {
            return;
        }

        $this->runWpAction($wpcli, 'core update', null, 'core', ['--minor']);
    }

    /**
     * Run WordPress's database upgrade routine. After a core update WordPress
     * only runs it on the next wp-admin visit; this does it now.
     */
    public function updateCoreDatabase(WpCli $wpcli): void
    {
        $this->runWpAction($wpcli, 'core update-db', null, 'core');
    }

    /**
     * Switch to another branch release. Upgrades are routine; going below the
     * installed version is flagged loudly, because WordPress never downgrades
     * the database schema.
     */
    public function confirmCoreVersionChange(): void
    {
        if ($this->refuseComposerCore()) {
            return;
        }

        $release = $this->coreRelease($this->coreTargetVersion);
        if ($release === null) {
            $this->addError('core', __('Pick a version from the list.'));

            return;
        }

        $installed = (string) ($this->core['version'] ?? '');
        $compat = CoreReleases::compatibility($release, $installed ?: null, $this->sitePhpVersion());
        if ($compat['blockers'] !== []) {
            $this->addError('core', implode(' ', $compat['blockers']));

            return;
        }

        $downgrade = $installed !== '' && version_compare($release['version'], $installed, '<');

        $this->openConfirmActionModal(
            method: 'changeCoreVersion',
            arguments: [$release['version']],
            title: $downgrade
                ? __('Downgrade WordPress to :v?', ['v' => $release['version']])
                : __('Switch WordPress to :v?', ['v' => $release['version']]),
            message: __('Replaces the WordPress core files with :v. Themes, plugins, uploads and wp-config.php are left alone.', ['v' => $release['version']]),
            confirmLabel: __('Install :v', ['v' => $release['version']]),
            destructive: $downgrade,
            details: [
                ['label' => __('Installed'), 'value' => $installed ?: '?', 'mono' => true],
                ['label' => __('New'), 'value' => $release['version'], 'mono' => true],
            ],
            warning: $downgrade
                ? __('The database stays at the newer schema — WordPress never downgrades it, and going back down is not a supported WordPress path. Take a snapshot in the Database tab first.')
                : ($compat['warnings'] !== [] ? implode(' ', $compat['warnings']) : null),
        );
    }

    public function changeCoreVersion(string $version, WpCli $wpcli): void
    {
        // Directly callable, not only through the modal: re-check everything.
        if ($this->refuseComposerCore()) {
            return;
        }

        $release = $this->coreRelease($version);
        if ($release === null) {
            $this->addError('core', __('Pick a version from the list.'));

            return;
        }

        $compat = CoreReleases::compatibility($release, $this->core['version'] ?? null, $this->sitePhpVersion());
        if ($compat['blockers'] !== []) {
            $this->addError('core', implode(' ', $compat['blockers']));

            return;
        }

        $this->runWpAction($wpcli, 'core update', null, 'core', ['--version='.$release['version'], '--force']);
    }

    /**
     * Read WP_AUTO_UPDATE_CORE. `config get` is instant; a missing constant
     * errors with "…is not defined…", which is distinct from a failed read.
     */
    public function loadCoreAutoUpdate(WpCli $wpcli): void
    {
        try {
            $result = $wpcli->run($this->site, 'config get', ['WP_AUTO_UPDATE_CORE', '--type=constant', '--format=json'], auth()->user());
        } catch (\Throwable) {
            $this->coreAutoUpdate = null;

            return;
        }

        if ($result->isFailed()) {
            $this->coreAutoUpdate = str_contains($result->stderr(), 'is not defined') ? 'default' : null;

            return;
        }

        // Viewers get "********" (masked config read) — decodes to null, never a value.
        $this->coreAutoUpdate = match (json_decode(trim($result->stdout()), true)) {
            true => 'all',
            false => 'off',
            'minor' => 'minor',
            default => null,
        };
    }

    /** `config set` runs at the Destructive tier (admin/owner); the view gates the same way. */
    public function setCoreAutoUpdate(string $mode, WpCli $wpcli): void
    {
        if ($this->refuseComposerCore()) {
            return;
        }

        $args = match ($mode) {
            'off' => ['WP_AUTO_UPDATE_CORE', 'false', '--raw', '--type=constant'],
            'minor' => ['WP_AUTO_UPDATE_CORE', 'minor', '--type=constant'],
            'all' => ['WP_AUTO_UPDATE_CORE', 'true', '--raw', '--type=constant'],
            default => null,
        };
        if ($args === null) {
            $this->addError('core', __('Unknown update policy.'));

            return;
        }

        if ($this->wp($wpcli, 'config set', $args, errorBag: 'core') === null) {
            return;
        }

        $this->coreAutoUpdate = $mode;
        $this->toastSuccess(__('Queued: automatic core updates set to :mode.', ['mode' => $mode]));
    }

    /** Restore the official files for the installed version — the fix when Verify flags changed core files. */
    public function confirmRepairCore(): void
    {
        if ($this->refuseComposerCore()) {
            return;
        }

        $version = (string) ($this->core['version'] ?? '');
        if (preg_match('/^\d+\.\d+(\.\d+)?$/', $version) !== 1) {
            $this->addError('core', __('Check the installed version first.'));

            return;
        }

        $this->openConfirmActionModal(
            method: 'repairCore',
            arguments: [$version],
            title: __('Reinstall WordPress :v core files?', ['v' => $version]),
            message: __('Downloads the official :v package and writes it over the core files, restoring anything that was modified. wp-content — themes, plugins, uploads — and wp-config.php are not touched.', ['v' => $version]),
            confirmLabel: __('Reinstall core files'),
        );
    }

    public function repairCore(string $version, WpCli $wpcli): void
    {
        if ($this->refuseComposerCore()) {
            return;
        }

        // Repair only ever re-lays the installed version; changing versions is
        // changeCoreVersion's job, with its own checks.
        if ($version === '' || $version !== (string) ($this->core['version'] ?? '')) {
            $this->addError('core', __('Check the installed version first.'));

            return;
        }

        // --skip-content: core files only. `core download --force` copies over
        // the install without deleting anything, and skipping content keeps it
        // from reverting updated bundled themes/plugins to the package's copies.
        $this->runWpAction($wpcli, 'core download', null, 'core', ['--version='.$version, '--force', '--skip-content']);
    }

    public function loadCoreLanguages(WpCli $wpcli): void
    {
        $rows = $this->readJsonRows($wpcli, 'language core list', ['--fields=language,english_name,native_name,status', '--format=json'], 'core');

        $this->coreLanguages = $rows === null ? [] : array_map(static fn (array $row): array => [
            'code' => (string) ($row['language'] ?? ''),
            'name' => (string) ($row['english_name'] ?? ''),
            'native' => (string) ($row['native_name'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
        ], $rows);
        $this->coreLanguagesLoaded = true;
    }

    /** Install (if needed) and activate a site language — wp-cli activates an already-installed one too. */
    public function installCoreLanguage(WpCli $wpcli): void
    {
        $code = trim($this->coreLanguageInstall);
        if (preg_match('/^[a-z]{2,3}(_[A-Z]{2})?(_[a-z0-9]+)?$/', $code) !== 1
            || ($this->coreLanguagesLoaded && ! collect($this->coreLanguages)->contains('code', $code))) {
            $this->addError('core', __('Pick a language from the list.'));

            return;
        }

        $this->runWpAction($wpcli, 'language core install', $code, 'core', ['--activate']);
        $this->coreLanguageInstall = '';
    }

    /**
     * Bedrock pins core in composer.json: wp-cli rewriting web/wp would drift
     * from composer.lock and be undone by the next composer install.
     */
    private function coreManagedByComposer(): bool
    {
        return app(GitSourceMaterializerFactory::class)->layoutOf($this->site) === 'bedrock';
    }

    /** Refused in the method as well as hidden in the view: these are callable directly. */
    private function refuseComposerCore(): bool
    {
        if (! $this->coreManagedByComposer()) {
            return false;
        }

        $this->addError('core', __('This is a Bedrock site: WordPress core is a Composer dependency. Change roots/wordpress in composer.json and deploy instead.'));

        return true;
    }

    /** @return array{version: string, php: string, mysql: string}|null */
    private function coreRelease(string $version): ?array
    {
        return collect(app(CoreReleases::class)->branches())->firstWhere('version', trim($version));
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
    public function takeSnapshot(): void
    {
        $org = $this->site->organization;
        if ($org === null || ! $org->hasAdminAccess(auth()->user())) {
            $this->addError('snapshots', __('Admin or owner role required to take snapshots.'));

            return;
        }

        // Queued: the dump runs over SSH and can take many minutes. It ran in
        // the request before, holding it open for the whole mysqldump.
        TakeSiteSnapshotJob::dispatch((string) $this->site->id, (string) auth()->id());
        $this->toastSuccess(__('Snapshot started. It appears below and updates when the dump finishes.'));
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

        // disable_wp_cron is not toggled here any more: flipping the constant
        // alone, with no crontab entry behind it, stopped every scheduled task.
        // The Cron tab's handler switch does both halves in order.
        if ($opinionKey === 'disable_wp_cron') {
            $this->addError('hardening', __('wp-cron is managed from the Cron tab, which installs the crontab entry that replaces it.'));

            return;
        }

        $allowed = ['disallow_file_edit', 'force_ssl_admin', 'disallow_file_mods'];
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
            'disallow_file_mods' => 'DISALLOW_FILE_MODS',
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

    /** @param  array<string, string>  $secrets  Passed to the box, redacted in the run row and audit log. */
    private function wp(WpCli $wpcli, string $command, array $args = [], bool $mutating = true, string $errorBag = 'tools', array $secrets = []): ?string
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
                secrets: $secrets,
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

    /** WordPress 6.6+ autoloads all of these (wp_autoload_values_to_autoload()); wp-cli's --autoload=on matches only on/yes. */
    private const AUTOLOAD_VALUES = ['yes', 'on', 'auto-on', 'auto'];

    /** Largest tables — where the weight is, before deciding what to clean. */
    public function loadDbTables(WpCli $wpcli): void
    {
        $this->resetErrorBag('database');

        // `db size --tables` emits table rows only (never the whole-database
        // row), each Size as "12345 B".
        $rows = $this->readJsonRows($wpcli, 'db size', ['--tables', '--format=json'], 'database');
        if ($rows === null) {
            $this->dbTables = [];

            return;
        }

        $tables = array_map(static fn (array $row): array => [
            'name' => (string) ($row['Name'] ?? ''),
            'bytes' => (int) preg_replace('/\D/', '', (string) ($row['Size'] ?? '0')),
        ], $rows);
        usort($tables, static fn (array $a, array $b): int => $b['bytes'] <=> $a['bytes']);

        $this->dbTables = $tables;
    }

    /**
     * Autoloaded options load on every request; WordPress Site Health warns past
     * 800 KB. Behind a button, not wire:init: the full option-name list lands
     * in the run log each time.
     */
    public function loadAutoloadAudit(WpCli $wpcli): void
    {
        $this->resetErrorBag('database');

        // ponytail: fetches every option's name+size and filters here, because
        // --autoload=on misses 6.6's auto/auto-on. Fine into the tens of thousands.
        $rows = $this->readJsonRows($wpcli, 'option list', ['--fields=option_name,size_bytes,autoload', '--format=json'], 'database');
        if ($rows === null) {
            $this->autoloadAudit = null;

            return;
        }

        $autoloaded = array_values(array_map(
            static fn (array $row): array => ['name' => (string) ($row['option_name'] ?? ''), 'bytes' => (int) ($row['size_bytes'] ?? 0)],
            array_filter($rows, static fn (array $row): bool => in_array((string) ($row['autoload'] ?? ''), self::AUTOLOAD_VALUES, true)),
        ));
        usort($autoloaded, static fn (array $a, array $b): int => $b['bytes'] <=> $a['bytes']);

        $this->autoloadAudit = [
            'total' => array_sum(array_column($autoloaded, 'bytes')),
            'count' => count($autoloaded),
            'top' => array_slice($autoloaded, 0, 15),
        ];
    }

    /**
     * Stop autoloading one option. It still works — get_option() falls back to
     * a query — it just stops riding along on every request. `option
     * set-autoload` runs at the Destructive tier (admin/owner).
     */
    public function stopAutoloading(string $option, WpCli $wpcli): void
    {
        if (! collect($this->autoloadAudit['top'] ?? [])->contains('name', $option)) {
            $this->addError('database', __('Pick an option from the list.'));

            return;
        }

        if ($this->wp($wpcli, 'option set-autoload', [$option, 'off'], errorBag: 'database') === null) {
            return;
        }

        $this->autoloadAudit['top'] = array_values(array_filter($this->autoloadAudit['top'], static fn (array $o): bool => $o['name'] !== $option));
        $this->toastSuccess(__('Queued: :option no longer autoloads.', ['option' => $option]));
    }

    /**
     * Cleanup jobs. The SQL ones run through `db query`, which wp-cli's gate
     * rates recoverable — so the destructive check is made here explicitly:
     * deleted revisions do not come back.
     *
     * @return array{title: string, message: string, sql: ?string}|null
     */
    private function dbCleanup(string $kind): ?array
    {
        $p = $this->wpTablePrefix();
        $orphanPostmeta = $p === null ? '' : "DELETE pm FROM `{$p}postmeta` pm LEFT JOIN `{$p}posts` p ON p.ID = pm.post_id WHERE p.ID IS NULL;";

        return match ($kind) {
            'transients' => ['title' => __('Delete expired transients?'), 'message' => __('Removes cached values that have already expired. Nothing live is lost.'), 'sql' => null],
            'revisions' => ['title' => __('Delete all post revisions?'), 'message' => __('Removes every saved revision and its metadata. Published content is untouched, but the revision history is gone for good.'), 'sql' => $p === null ? null : "DELETE FROM `{$p}posts` WHERE post_type = 'revision'; ".$orphanPostmeta],
            'drafts' => ['title' => __('Delete auto-drafts?'), 'message' => __('Removes the empty auto-drafts WordPress creates when an editor opens. Real drafts are kept.'), 'sql' => $p === null ? null : "DELETE FROM `{$p}posts` WHERE post_status = 'auto-draft'; ".$orphanPostmeta],
            'comments' => ['title' => __('Delete spam and trashed comments?'), 'message' => __('Removes comments marked spam or already in the trash, with their metadata. Approved and pending comments are kept.'), 'sql' => $p === null ? null : "DELETE FROM `{$p}comments` WHERE comment_approved IN ('spam', 'trash'); DELETE cm FROM `{$p}commentmeta` cm LEFT JOIN `{$p}comments` c ON c.comment_ID = cm.comment_id WHERE c.comment_ID IS NULL;"],
            default => null,
        };
    }

    public function confirmDbCleanup(string $kind, WpCli $wpcli): void
    {
        if ($kind !== 'transients' && ! $this->canDestroyHere()) {
            $this->addError('database', __('Cleanup needs an admin or owner.'));

            return;
        }

        // The SQL cleanups need the table prefix, read from the table list.
        if ($kind !== 'transients' && $this->dbTables === null) {
            $this->loadDbTables($wpcli);
        }

        $cleanup = $this->dbCleanup($kind);
        if ($cleanup === null) {
            return;
        }
        if ($kind !== 'transients' && $cleanup['sql'] === null) {
            $this->addError('database', __('Could not work out the WordPress table prefix from the table list.'));

            return;
        }

        $this->openConfirmActionModal(
            method: 'runDbCleanup',
            arguments: [$kind],
            title: $cleanup['title'],
            message: $cleanup['message'],
            confirmLabel: __('Delete'),
            destructive: $kind !== 'transients',
            warning: $kind !== 'transients' ? __('This cannot be undone. Take a snapshot first if you might want any of it back.') : null,
        );
    }

    public function runDbCleanup(string $kind, WpCli $wpcli): void
    {
        if ($kind === 'transients') {
            if ($this->wp($wpcli, 'transient delete', ['--expired'], errorBag: 'database') !== null) {
                $this->toastSuccess(__('Queued: deleting expired transients.'));
            }

            return;
        }

        // Directly callable, so the destructive check is repeated here.
        if (! $this->canDestroyHere()) {
            $this->addError('database', __('Cleanup needs an admin or owner.'));

            return;
        }

        $sql = $this->dbCleanup($kind)['sql'] ?? null;
        if ($sql === null) {
            $this->addError('database', __('Could not work out the WordPress table prefix from the table list.'));

            return;
        }

        if ($this->wp($wpcli, 'db query', [$sql], errorBag: 'database') !== null) {
            $this->toastSuccess(__('Queued: cleanup running. Check the tables again once it finishes.'));
        }
    }

    /**
     * The WordPress table prefix, from the loaded table list: the shortest P
     * with both P.posts and P.options. Shortest, because multisite adds
     * wp_2_posts next to wp_posts. Restricted to [A-Za-z0-9_] — it is spliced
     * into SQL inside backticks.
     */
    private function wpTablePrefix(): ?string
    {
        $names = array_column($this->dbTables ?? [], 'name');
        $best = null;
        foreach ($names as $name) {
            if (! str_ends_with($name, 'posts')) {
                continue;
            }
            $prefix = substr($name, 0, -5);
            if (preg_match('/^[A-Za-z0-9_]*$/', $prefix) === 1
                && in_array($prefix.'options', $names, true)
                && ($best === null || strlen($prefix) < strlen($best))) {
                $best = $prefix;
            }
        }

        return $best;
    }

    private function canDestroyHere(): bool
    {
        return app(RemoteCliPermissions::class)->can(auth()->user(), $this->site, RiskLevel::Destructive);
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
        if (! $this->isValidCronHook($hook)) {
            $this->addError('cron', __('Unknown event.'));

            return;
        }

        if ($this->wp($wpcli, 'cron event run', [$hook], errorBag: 'cron') !== null) {
            $this->toastSuccess(__('Queued: running :hook.', ['hook' => $hook]));
        }
    }

    /** Run everything due — what the system crontab entry does each minute. */
    public function runDueCronEvents(WpCli $wpcli): void
    {
        if ($this->wp($wpcli, 'cron event run', ['--due-now'], errorBag: 'cron') !== null) {
            $this->toastSuccess(__('Queued: running every due event.'));
        }
    }

    public function confirmDeleteCronEvent(string $hook): void
    {
        if (! $this->isValidCronHook($hook)) {
            $this->addError('cron', __('Unknown event.'));

            return;
        }

        $this->openConfirmActionModal(
            method: 'deleteCronEvent',
            arguments: [$hook],
            title: __('Unschedule :hook?', ['hook' => $hook]),
            message: __('Removes every scheduled occurrence of this hook. A plugin that still needs it usually schedules it again on its next load.'),
            confirmLabel: __('Unschedule'),
            destructive: true,
            details: [['label' => __('Hook'), 'value' => $hook, 'mono' => true]],
        );
    }

    public function deleteCronEvent(string $hook, WpCli $wpcli): void
    {
        if (! $this->isValidCronHook($hook)) {
            $this->addError('cron', __('Unknown event.'));

            return;
        }

        if ($this->wp($wpcli, 'cron event delete', [$hook], errorBag: 'cron') === null) {
            return;
        }

        $this->cronEvents = array_values(array_filter($this->cronEvents, static fn (array $e): bool => ($e['hook'] ?? '') !== $hook));
        $this->toastSuccess(__('Queued: unscheduling :hook.', ['hook' => $hook]));
    }

    /**
     * Events for the table, filtered, each flagged overdue when it was due
     * more than 10 minutes ago — the tell-tale of cron not running at all.
     *
     * @return array{rows: list<array{hook: string, next_run: string, relative: string, recurrence: string, overdue: bool}>, overdue: int}
     */
    public function cronEventRows(): array
    {
        $needle = mb_strtolower(trim($this->cronEventFilter));
        $cutoff = now('UTC')->subMinutes(10);
        $rows = [];
        $overdue = 0;

        foreach ($this->cronEvents as $event) {
            $next = (string) ($event['next_run_gmt'] ?? '');
            $late = false;
            if ($next !== '') {
                try {
                    $late = CarbonImmutable::parse($next, 'UTC')->lt($cutoff);
                } catch (\Throwable) {
                    $late = false;
                }
            }
            $overdue += $late ? 1 : 0;

            $hook = (string) ($event['hook'] ?? '');
            if ($needle !== '' && ! str_contains(mb_strtolower($hook), $needle)) {
                continue;
            }

            $rows[] = [
                'hook' => $hook,
                'next_run' => $next,
                'relative' => (string) ($event['next_run_relative'] ?? ''),
                'recurrence' => (string) ($event['recurrence'] ?? ''),
                'overdue' => $late,
            ];
        }

        return ['rows' => $rows, 'overdue' => $overdue];
    }

    private function isValidCronHook(string $hook): bool
    {
        return preg_match('/^[A-Za-z0-9_.:\/][A-Za-z0-9_.:\/-]{0,190}$/', $hook) === 1;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Tools: site settings, debug logging, thumbnails.
    // ═════════════════════════════════════════════════════════════════════════

    /** The options operators change in wp-admin most, read in one go. */
    public function loadSiteSettings(WpCli $wpcli): void
    {
        $this->resetErrorBag('tools');

        $settings = [
            'blogname' => $this->readOption($wpcli, 'blogname'),
            'blogdescription' => $this->readOption($wpcli, 'blogdescription'),
            'blog_public' => $this->readOption($wpcli, 'blog_public'),
            'home' => $this->readOption($wpcli, 'home'),
            'siteurl' => $this->readOption($wpcli, 'siteurl'),
            'debug' => ($debug = $this->configConstant($wpcli, 'WP_DEBUG')) === null ? null : $debug === true,
        ];

        $this->siteSettings = $settings;
        $this->settingsTitle = (string) $settings['blogname'];
        $this->settingsTagline = (string) $settings['blogdescription'];
        $this->settingsHome = (string) $settings['home'];
        $this->settingsSiteurl = (string) $settings['siteurl'];
    }

    public function saveSiteIdentity(WpCli $wpcli): void
    {
        $title = trim($this->settingsTitle);
        $tagline = trim($this->settingsTagline);

        if ($title === '') {
            $this->addError('tools', __('The site title can\'t be empty.'));

            return;
        }
        foreach ([$title, $tagline] as $value) {
            // A leading dash would reach wp-cli as a flag, not a value.
            if (mb_strlen($value) > 200 || str_starts_with($value, '-') || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                $this->addError('tools', __('Keep the title and tagline under 200 characters, on one line, not starting with a dash.'));

                return;
            }
        }

        foreach (['blogname' => $title, 'blogdescription' => $tagline] as $option => $value) {
            if ($value !== (string) ($this->siteSettings[$option] ?? '')) {
                if ($this->wp($wpcli, 'option update', [$option, $value]) === null) {
                    return;
                }
                $this->siteSettings[$option] = $value;
            }
        }

        $this->toastSuccess(__('Queued: site identity updated.'));
    }

    /** "Discourage search engines" — the switch staging sites forget, and live sites leave on by accident. */
    public function setSearchVisibility(bool $visible, WpCli $wpcli): void
    {
        if ($this->wp($wpcli, 'option update', ['blog_public', $visible ? '1' : '0']) === null) {
            return;
        }

        if ($this->siteSettings !== null) {
            $this->siteSettings['blog_public'] = $visible ? '1' : '0';
        }
        $this->toastSuccess($visible
            ? __('Queued: search engines may index this site.')
            : __('Queued: search engines are asked not to index this site.'));
    }

    public function confirmSiteAddress(): void
    {
        if ($this->refuseComposerCore()) {
            return;
        }

        $home = $this->normalizeSiteUrl($this->settingsHome);
        $siteurl = $this->normalizeSiteUrl($this->settingsSiteurl);
        if ($home === null || $siteurl === null) {
            $this->addError('tools', __('Enter full http:// or https:// addresses.'));

            return;
        }

        $this->openConfirmActionModal(
            method: 'saveSiteAddress',
            arguments: [$home, $siteurl],
            title: __('Change the site address?'),
            message: __('WordPress starts building every link and redirect from the new address immediately. Links stored in posts keep the old one — run Search and replace afterwards.'),
            confirmLabel: __('Change address'),
            destructive: true,
            details: [
                ['label' => __('Site address (home)'), 'value' => $home, 'mono' => true],
                ['label' => __('WordPress address (siteurl)'), 'value' => $siteurl, 'mono' => true],
            ],
            warning: __('If the address does not change afterwards, WP_HOME / WP_SITEURL constants in wp-config.php are overriding it.'),
        );
    }

    public function saveSiteAddress(string $home, string $siteurl, WpCli $wpcli): void
    {
        // Directly callable, so re-checked.
        if ($this->refuseComposerCore()) {
            return;
        }

        $home = $this->normalizeSiteUrl($home);
        $siteurl = $this->normalizeSiteUrl($siteurl);
        if ($home === null || $siteurl === null) {
            $this->addError('tools', __('Enter full http:// or https:// addresses.'));

            return;
        }

        if ($this->wp($wpcli, 'option update', ['home', $home]) === null || $this->wp($wpcli, 'option update', ['siteurl', $siteurl]) === null) {
            return;
        }

        if ($this->siteSettings !== null) {
            $this->siteSettings['home'] = $home;
            $this->siteSettings['siteurl'] = $siteurl;
        }
        $this->toastSuccess(__('Queued: site address changed.'));
    }

    /**
     * Debug logging: errors to wp-content/debug.log, never to visitors.
     * `config set` runs at the Destructive tier (admin/owner).
     */
    public function setDebugLogging(bool $on, WpCli $wpcli): void
    {
        if ($this->coreManagedByComposer()) {
            $this->addError('tools', __('This is a Bedrock site: set WP_DEBUG in .env or config/environments instead.'));

            return;
        }
        if (! $this->canDestroyHere()) {
            $this->addError('tools', __('Debug logging needs an admin or owner.'));

            return;
        }

        $sets = $on
            ? [['WP_DEBUG', 'true'], ['WP_DEBUG_LOG', 'true'], ['WP_DEBUG_DISPLAY', 'false']]
            : [['WP_DEBUG', 'false']];
        foreach ($sets as [$constant, $value]) {
            if ($this->wp($wpcli, 'config set', [$constant, $value, '--raw', '--type=constant']) === null) {
                return;
            }
        }

        if ($this->siteSettings !== null) {
            $this->siteSettings['debug'] = $on;
        }
        $this->toastSuccess($on
            ? __('Queued: debug logging on — errors go to wp-content/debug.log, never to visitors.')
            : __('Queued: debug logging off.'));
    }

    /** Only the missing sizes — the fix after switching to a theme with new image sizes. */
    public function regenerateThumbnails(WpCli $wpcli): void
    {
        if (! $this->canDestroyHere()) {
            $this->addError('tools', __('Regenerating thumbnails needs an admin or owner.'));

            return;
        }

        if ($this->wp($wpcli, 'media regenerate', ['--only-missing', '--yes']) !== null) {
            $this->toastSuccess(__('Queued: generating missing thumbnail sizes.'));
        }
    }

    private function normalizeSiteUrl(string $url): ?string
    {
        $url = rtrim(trim($url), '/');

        return filter_var($url, FILTER_VALIDATE_URL) !== false && preg_match('#^https?://#i', $url) === 1 ? $url : null;
    }

    /** One option's value; null when unreadable (or masked for this viewer). */
    private function readOption(WpCli $wpcli, string $option): ?string
    {
        try {
            $result = $wpcli->run($this->site, 'option get', [$option], auth()->user());
        } catch (\Throwable) {
            return null;
        }

        $value = trim($result->stdout());

        return $result->isFailed() || $value === '********' ? null : $value;
    }

    /** configConstant()'s answer for a constant wp-config.php does not define. */
    private const UNDEFINED = "\0undefined";

    /**
     * A wp-config constant, json-decoded; self::UNDEFINED when it is not
     * defined (distinct from defined-as-false: WordPress shows errors when
     * WP_DEBUG is on and WP_DEBUG_DISPLAY is undefined); null when the read
     * failed or the value is masked for this viewer.
     */
    private function configConstant(WpCli $wpcli, string $name): mixed
    {
        try {
            $result = $wpcli->run($this->site, 'config get', [$name, '--type=constant', '--format=json'], auth()->user());
        } catch (\Throwable) {
            return null;
        }

        if ($result->isFailed()) {
            return str_contains($result->stderr(), 'is not defined') ? self::UNDEFINED : null;
        }

        return json_decode(trim($result->stdout()), true);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Hardening: scan, registration, permissions, login protection.
    // ═════════════════════════════════════════════════════════════════════════

    /** Plugins that protect the login form; any one active passes the check. */
    private const LOGIN_PROTECTION_PLUGINS = ['limit-login-attempts-reloaded', 'wordfence', 'wp-2fa', 'two-factor', 'loginizer', 'all-in-one-wp-security-and-firewall', 'better-wp-security'];

    /**
     * Read-only security scan: about ten wp-cli reads over SSH, each check
     * degrading to "unknown" on its own rather than failing the whole scan.
     * Gated on update rights: viewers get masked config values, which must
     * never be scored as a pass.
     */
    public function runSecurityScan(WpCli $wpcli, AdvisoryProvider $advisories): void
    {
        $this->authorize('update', $this->site);

        $checks = [];
        $add = static function (string $key, string $label, string $status, string $detail) use (&$checks): void {
            $checks[] = ['key' => $key, 'label' => $label, 'status' => $status, 'detail' => $detail];
        };

        $version = $this->siteWordPressVersion($wpcli);
        $status = app(CoreReleases::class)->status($version);
        $add('core', __('WordPress core'), match ($status) {
            'latest' => 'pass', 'outdated' => 'warn', 'insecure' => 'fail', default => 'unknown',
        }, $version !== null ? __('Version :v — :s', ['v' => $version, 's' => $status ?? __('status unknown')]) : __('Could not read the version.'));

        $this->loadPlugins($wpcli, $advisories);
        if ($this->getErrorBag()->has('plugins')) {
            $add('plugins', __('Plugins'), 'unknown', __('Could not list plugins.'));
        } else {
            $vulnerable = collect($this->plugins)->filter(static fn (array $p): bool => ! empty($p['advisories']))->pluck('name');
            $outdated = collect($this->plugins)->where('update', 'available')->pluck('name');
            $add('plugins', __('Plugins'), $vulnerable->isNotEmpty() ? 'fail' : ($outdated->isNotEmpty() ? 'warn' : 'pass'), $vulnerable->isNotEmpty()
                ? __('Known vulnerabilities: :list', ['list' => $vulnerable->implode(', ')])
                : ($outdated->isNotEmpty() ? __('Updates waiting: :list', ['list' => $outdated->implode(', ')]) : __('No known vulnerabilities, all up to date.')));

            $protected = collect($this->plugins)->contains(static fn (array $p): bool => $p['status'] === 'active' && in_array($p['name'], self::LOGIN_PROTECTION_PLUGINS, true));
            $add('login', __('Login protection'), $protected ? 'pass' : 'warn', $protected
                ? __('A login-protection plugin is active.')
                : __('Nothing limits password guessing on wp-login.php.'));
        }

        $this->loadThemes($wpcli);
        if ($this->getErrorBag()->has('themes')) {
            $add('themes', __('Themes'), 'unknown', __('Could not list themes.'));
        } else {
            $inactive = collect($this->themes)->where('status', 'inactive')->count();
            $outdated = collect($this->themes)->where('update', 'available')->count();
            $add('themes', __('Themes'), $outdated > 0 || $inactive > 1 ? 'warn' : 'pass', trim(
                ($outdated > 0 ? __(':n theme update(s) waiting.', ['n' => $outdated]).' ' : '')
                .($inactive > 1 ? __(':n inactive themes — unused code is still attack surface; keep one default as a fallback.', ['n' => $inactive]) : '')
            ) ?: __('Up to date, no unused themes.'));
        }

        $this->loadUsers($wpcli);
        if ($this->getErrorBag()->has('users')) {
            $add('users', __('Accounts'), 'unknown', __('Could not list users.'));
        } else {
            $admins = collect($this->users)->filter(fn (array $u): bool => $this->hasRole($u, 'administrator'))->count();
            $adminLogin = collect($this->users)->contains(static fn (array $u): bool => strtolower($u['login']) === 'admin');
            $add('users', __('Accounts'), $adminLogin ? 'warn' : 'pass', ($adminLogin
                ? __('An account is named "admin" — the first username bots try.').' '
                : '').__(':n administrator(s).', ['n' => $admins]));
        }

        $open = $this->readOption($wpcli, 'users_can_register');
        $role = $this->readOption($wpcli, 'default_role');
        $add('registration', __('Registration'), match (true) {
            $open === null => 'unknown',
            $open === '1' && $role === 'administrator' => 'fail',
            $open === '1' => 'warn',
            default => 'pass',
        }, match (true) {
            $open === null => __('Could not read the setting.'),
            $open === '1' => __('Anyone can register, as :role.', ['role' => $role ?? '?']),
            default => __('Registration is closed.'),
        });

        foreach ([
            ['DISALLOW_FILE_EDIT', 'file_edit', __('Admin file editor'), __('The wp-admin file editor is off.'), __('The wp-admin file editor is on — a stolen admin login can rewrite PHP.')],
            ['FORCE_SSL_ADMIN', 'ssl_admin', __('Admin over HTTPS'), __('wp-admin is forced to HTTPS.'), __('wp-admin can be reached over plain HTTP.')],
        ] as [$constant, $key, $label, $passText, $warnText]) {
            $value = $this->configConstant($wpcli, $constant);
            $add($key, $label, $value === null ? 'unknown' : ($value === true ? 'pass' : 'warn'), $value === null ? __('Could not read :c.', ['c' => $constant]) : ($value === true ? $passText : $warnText));
        }

        $debug = $this->configConstant($wpcli, 'WP_DEBUG');
        $display = $this->configConstant($wpcli, 'WP_DEBUG_DISPLAY');
        // Undefined WP_DEBUG_DISPLAY means "display" — only an explicit false hides errors.
        $shown = $debug === true && $display !== false;
        $add('debug', __('Error display'), $debug === null ? 'unknown' : ($shown ? 'fail' : 'pass'), match (true) {
            $debug === null => __('Could not read WP_DEBUG.'),
            $shown => __('Debug output is shown to visitors — it leaks paths and queries.'),
            default => __('Errors are not shown to visitors.'),
        });

        $this->securityScan = $checks;
    }

    /** Registration closed, and the default role back to subscriber. */
    public function lockDownRegistration(WpCli $wpcli): void
    {
        $org = $this->site->organization;
        if ($org === null || ! $org->hasAdminAccess(auth()->user())) {
            $this->addError('hardening', __('Admin or owner role required.'));

            return;
        }

        if ($this->wp($wpcli, 'option update', ['users_can_register', '0'], errorBag: 'hardening') === null
            || $this->wp($wpcli, 'option update', ['default_role', 'subscriber'], errorBag: 'hardening') === null) {
            return;
        }
        $this->toastSuccess(__('Queued: registration closed, default role set to subscriber.'));
    }

    /** Re-assert dply's ownership and modes on the site's files (queued). */
    public function resetFilePermissions(): void
    {
        $org = $this->site->organization;
        if ($org === null || ! $org->hasAdminAccess(auth()->user())) {
            $this->addError('hardening', __('Admin or owner role required.'));

            return;
        }

        SiteResetPermissionsJob::dispatch((string) $this->site->id, (string) auth()->id());
        $this->toastSuccess(__('Resetting file permissions in the background.'));
    }

    public function installLoginProtection(WpCli $wpcli): void
    {
        $this->runWpAction($wpcli, 'plugin install', 'limit-login-attempts-reloaded', 'hardening', ['--activate']);
    }

    /**
     * 10. User maintenance. The Users tab was read-only, so the two things an
     * operator actually needs — lock someone out, or get back in — meant
     * dropping to the console.
     */
    public function startResetUserPassword(string $login): void
    {
        if (! $this->isValidUserLogin($login)) {
            return;
        }

        $this->resettingPasswordLogin = $login;
        $this->resetPasswordValue = '';
        $this->editingUserId = null;
        $this->deletingUserId = null;
    }

    public function cancelResetUserPassword(): void
    {
        $this->resettingPasswordLogin = null;
        $this->resetPasswordValue = '';
    }

    public function resetUserPassword(string $login, WpCli $wpcli): void
    {
        $typed = $this->resetPasswordValue;
        $this->resetPasswordValue = '';
        $this->resetErrorBag('users');

        if (! $this->isValidUserLogin($login)) {
            $this->addError('users', __('Unknown user.'));

            return;
        }

        if ($typed !== '' && (mb_strlen($typed) < 8 || preg_match('/[\x00-\x1F\x7F]/', $typed) === 1)) {
            $this->addError('users', __('Use at least 8 characters, on one line — or leave it blank to generate one.'));

            return;
        }

        $password = $typed !== '' ? $typed : Str::password(24, letters: true, numbers: true, symbols: false, spaces: false);

        // A secret, not an arg: args are stored in the run row and audit log.
        // Revealed only once the command is queued — never for a failed call.
        if ($this->wp($wpcli, 'user update', [$login, '--skip-email'], errorBag: 'users', secrets: ['user_pass' => $password]) === null) {
            return;
        }

        // Typed: they already know it — never echo it back.
        if ($typed !== '') {
            $this->resettingPasswordLogin = null;
            $this->toastSuccess(__('Queued: new password for :login.', ['login' => $login]));

            return;
        }

        // Generated: shown once, in the row they clicked, like every other
        // generated credential in dply.
        $this->revealedUserPassword = $password;
        $this->revealedUserLogin = $login;
        $this->toastSuccess(__('Password reset for :login — copy it now.', ['login' => $login]));
    }

    public function changeUserRole(string $login, string $role, WpCli $wpcli): void
    {
        if (! in_array($role, self::WP_ROLES, true) || ! $this->isValidUserLogin($login)) {
            $this->addError('users', __('Unknown role.'));

            return;
        }

        if ($this->wp($wpcli, 'user set-role', [$login, $role], errorBag: 'users') === null) {
            return;
        }
        $this->toastSuccess(__(':login is now :role.', ['login' => $login, 'role' => $role]));
    }

    /**
     * Create a WordPress user with a generated password, shown once. The
     * password travels as a RemoteCli secret, so the run row and audit log
     * record `--user_pass=[redacted]`.
     */
    public function createUser(WpCli $wpcli): void
    {
        $login = trim($this->newUserLogin);
        $email = trim($this->newUserEmail);
        $name = trim($this->newUserDisplayName);

        // Positional args: a leading dash would be read by wp-cli as a flag,
        // escaping or not — the login pattern and the email check both refuse it.
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._@-]{0,59}$/', $login) !== 1) {
            $this->addError('users', __('Use letters, numbers, dots, dashes, underscores or @ for the username.'));

            return;
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || str_starts_with($email, '-')) {
            $this->addError('users', __('Enter a valid email address.'));

            return;
        }
        if (! in_array($this->newUserRole, self::WP_ROLES, true)) {
            $this->addError('users', __('Unknown role.'));

            return;
        }
        if (! $this->isValidDisplayName($name)) {
            $this->addError('users', __('Keep the display name under 250 characters, on one line.'));

            return;
        }
        if (collect($this->users)->contains(fn (array $u): bool => strcasecmp($u['login'], $login) === 0 || strcasecmp($u['email'], $email) === 0)) {
            $this->addError('users', __('A user with that username or email already exists.'));

            return;
        }

        $args = [$login, $email, '--role='.$this->newUserRole, '--porcelain'];
        if ($name !== '') {
            $args[] = '--display_name='.$name;
        }
        if ($this->newUserSendEmail) {
            $args[] = '--send-email';
        }

        $password = Str::password(24, letters: true, numbers: true, symbols: false, spaces: false);
        if ($this->wp($wpcli, 'user create', $args, errorBag: 'users', secrets: ['user_pass' => $password]) === null) {
            return;
        }

        $this->revealedUserPassword = $password;
        $this->revealedUserLogin = $login;
        $this->reset('newUserLogin', 'newUserEmail', 'newUserDisplayName', 'newUserRole', 'newUserSendEmail', 'showCreateUser');
        $this->toastSuccess(__('Queued: creating :login — copy the password now.', ['login' => $login]));
    }

    public function startEditUser(string $id): void
    {
        $user = $this->findWpUser($id);
        if ($user === null) {
            return;
        }

        $this->editingUserId = $user['id'];
        $this->editUserEmail = $user['email'];
        $this->editUserDisplayName = $user['name'];
        $this->deletingUserId = null;
        $this->resettingPasswordLogin = null;
    }

    public function cancelEditUser(): void
    {
        $this->editingUserId = null;
    }

    public function saveUser(WpCli $wpcli): void
    {
        $user = $this->findWpUser((string) $this->editingUserId);
        if ($user === null) {
            return;
        }

        $email = trim($this->editUserEmail);
        $name = trim($this->editUserDisplayName);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->addError('users', __('Enter a valid email address.'));

            return;
        }
        if (! $this->isValidDisplayName($name)) {
            $this->addError('users', __('Keep the display name under 250 characters, on one line.'));

            return;
        }

        $args = [$user['id']];
        if ($email !== $user['email']) {
            $args[] = '--user_email='.$email;
        }
        if ($name !== $user['name']) {
            $args[] = '--display_name='.$name;
        }

        if (count($args) > 1) {
            // --skip-email: no "your email changed" notice from a dashboard edit.
            $args[] = '--skip-email';
            if ($this->wp($wpcli, 'user update', $args, errorBag: 'users') === null) {
                return;
            }
            $this->toastSuccess(__('Queued: updating :login.', ['login' => $user['login']]));
        }

        $this->editingUserId = null;
    }

    public function startDeleteUser(string $id): void
    {
        $user = $this->findWpUser($id);
        if ($user === null) {
            return;
        }

        if ($this->isLastAdministrator($user)) {
            $this->addError('users', __(':login is the only administrator — make someone else an administrator first.', ['login' => $user['login']]));

            return;
        }

        // Default heir: another administrator, else anyone else.
        $others = collect($this->users)->reject(fn (array $u): bool => $u['id'] === $user['id']);
        $heir = $others->first(fn (array $u): bool => $this->hasRole($u, 'administrator')) ?? $others->first();

        $this->deletingUserId = $user['id'];
        $this->deleteReassignTo = (string) ($heir['id'] ?? '');
        $this->editingUserId = null;
    }

    public function cancelDeleteUser(): void
    {
        $this->deletingUserId = null;
    }

    /**
     * Delete a user, handing their posts and pages to someone else — never
     * deleting content along with the account. The last-administrator check is
     * a guard against accidents, not a control: $users is client state.
     */
    public function deleteUser(WpCli $wpcli): void
    {
        $user = $this->findWpUser((string) $this->deletingUserId);
        if ($user === null) {
            return;
        }

        if ($this->isLastAdministrator($user)) {
            $this->addError('users', __(':login is the only administrator — make someone else an administrator first.', ['login' => $user['login']]));

            return;
        }

        $heir = $this->findWpUser($this->deleteReassignTo);
        if ($heir === null || $heir['id'] === $user['id']) {
            $this->addError('users', __('Pick who gets their posts and pages.'));

            return;
        }

        if ($this->wp($wpcli, 'user delete', [$user['id'], '--reassign='.$heir['id'], '--yes'], errorBag: 'users') === null) {
            return;
        }

        $this->deletingUserId = null;
        $this->toastSuccess(__('Queued: deleting :login. Their content goes to :heir.', ['login' => $user['login'], 'heir' => $heir['login']]));
    }

    /** Ends every session — the step after a leaked password or a departing admin. */
    public function logoutUserEverywhere(string $id, WpCli $wpcli): void
    {
        $user = $this->findWpUser($id);
        if ($user === null) {
            return;
        }

        if ($this->wp($wpcli, 'user session destroy', [$user['id'], '--all'], errorBag: 'users') === null) {
            return;
        }
        $this->toastSuccess(__('Queued: signing :login out everywhere.', ['login' => $user['login']]));
    }

    /**
     * Users matching the search box and role filter. Filtering the loaded list
     * costs nothing on the box.
     *
     * @return list<array{id: string, login: string, name: string, email: string, roles: string}>
     */
    public function filteredUsers(): array
    {
        $needle = mb_strtolower(trim($this->userFilter));

        return array_values(array_filter($this->users, function (array $u) use ($needle): bool {
            if ($this->userRoleFilter !== '' && ! $this->hasRole($u, $this->userRoleFilter)) {
                return false;
            }

            return $needle === '' || str_contains(mb_strtolower($u['login'].' '.$u['name'].' '.$u['email']), $needle);
        }));
    }

    /** @return array{id: string, login: string, name: string, email: string, roles: string}|null */
    private function findWpUser(string $id): ?array
    {
        if ($id === '' || ! ctype_digit($id)) {
            return null;
        }

        return collect($this->users)->firstWhere('id', $id);
    }

    /** @param  array{roles: string}  $user */
    private function hasRole(array $user, string $role): bool
    {
        return in_array($role, array_map('trim', explode(',', $user['roles'])), true);
    }

    /** @param  array{roles: string}  $user */
    private function isLastAdministrator(array $user): bool
    {
        return $this->hasRole($user, 'administrator')
            && collect($this->users)->filter(fn (array $u): bool => $this->hasRole($u, 'administrator'))->count() <= 1;
    }

    /** WordPress logins may hold spaces; never a leading dash (wp-cli would read a flag). */
    private function isValidUserLogin(string $login): bool
    {
        return preg_match('/^[A-Za-z0-9_.@][A-Za-z0-9 _.@-]{0,59}$/', $login) === 1;
    }

    private function isValidDisplayName(string $name): bool
    {
        return mb_strlen($name) <= 250 && preg_match('/[\x00-\x1F\x7F]/', $name) !== 1;
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
            : $this->markInstalled(app(PluginDirectory::class)->search($term, 8), $this->plugins);
    }

    /** 2. Recommendations: popular or featured, minus what is already installed. */
    public function loadPluginRecommendations(): void
    {
        $list = $this->pluginRecommendationList === 'featured' ? 'featured' : 'popular';

        // Fetch extra so hiding installed plugins still fills the row.
        $picks = array_filter(
            $this->markInstalled(app(PluginDirectory::class)->browse($list, 16), $this->plugins),
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

    // ═════════════════════════════════════════════════════════════════════════
    // Theme directory: the plugin set, adapted — search, recommendations,
    // details, versions, bulk — plus child themes. Same rule: directory reads
    // run on the control plane; only installs touch the box.
    // ═════════════════════════════════════════════════════════════════════════

    /** 1. Autocomplete, fired by the debounced search input. */
    public function updatedThemeSearch(): void
    {
        $term = trim($this->themeSearch);

        $this->themeSuggestions = mb_strlen($term) < 2
            ? []
            : $this->markInstalled(app(ThemeDirectory::class)->search($term, 8), $this->themes);
    }

    /** 2. Recommendations: popular, featured or new, minus what is already installed. */
    public function loadThemeRecommendations(): void
    {
        $list = match ($this->themeRecommendationList) {
            'featured' => 'featured',
            'new' => 'new',
            default => 'popular',
        };

        $picks = array_filter(
            $this->markInstalled(app(ThemeDirectory::class)->browse($list, 12), $this->themes),
            static fn (array $t): bool => ! $t['installed'],
        );

        $this->themeRecommendations = array_slice(array_values($picks), 0, 8);
        $this->themeRecommendationsLoaded = true;
    }

    public function setThemeRecommendationList(string $list): void
    {
        $this->themeRecommendationList = in_array($list, ['featured', 'new'], true) ? $list : 'popular';
        $this->loadThemeRecommendations();
    }

    /** 3. Details, screenshot, live demo and a compatibility check before install. */
    public function showThemeDetail(string $slug, WpCli $wpcli): void
    {
        $info = app(ThemeDirectory::class)->info(strtolower(trim($slug)));
        if ($info === null) {
            $this->addError('themes', __('Could not load :slug from WordPress.org.', ['slug' => $slug]));

            return;
        }

        $info['installed'] = $this->isThemeInstalled((string) $info['slug']);
        $info['compatibility'] = PluginDirectory::compatibility(
            $info,
            $this->siteWordPressVersion($wpcli),
            $this->sitePhpVersion(),
        );

        $this->themeDetail = $info;
        $this->themeDetailVersion = '';
        $this->themeDetailActivate = false;
        $this->themeSuggestions = [];
    }

    public function closeThemeDetail(): void
    {
        $this->themeDetail = null;
        $this->themeDetailVersion = '';
    }

    /**
     * 4. Install from the detail card — latest or a pinned version, activated
     * only when asked. A pinned version with --force rolls back a bad update.
     */
    public function installThemeFromDirectory(WpCli $wpcli): void
    {
        $slug = (string) ($this->themeDetail['slug'] ?? '');
        if ($slug === '') {
            return;
        }

        $blockers = (array) ($this->themeDetail['compatibility']['blockers'] ?? []);
        if ($blockers !== []) {
            $this->addError('themes', implode(' ', $blockers));

            return;
        }

        $args = $this->themeDetailActivate ? ['--activate'] : [];
        $version = trim($this->themeDetailVersion);
        if ($version !== '') {
            if (! in_array($version, (array) ($this->themeDetail['versions'] ?? []), true)) {
                $this->addError('themes', __('Pick a version from the list.'));

                return;
            }
            $args[] = '--version='.$version;
            $args[] = '--force';
        }

        $this->runWpAction($wpcli, 'theme install', $slug, 'themes', $args);

        $this->themeDetail = null;
        $this->themeDetailVersion = '';
        $this->themeSearch = '';
    }

    /**
     * 5. One action across every ticked theme, as one wp-cli call. Activate is
     * absent (only one theme can be active) and so is delete (per-row confirm).
     */
    public function bulkThemeAction(string $action, WpCli $wpcli): void
    {
        $command = match ($action) {
            'update' => 'theme update',
            'auto-on' => 'theme auto-updates enable',
            'auto-off' => 'theme auto-updates disable',
            default => null,
        };

        $slugs = array_values(array_filter(
            $this->selectedThemes,
            fn (string $slug): bool => $this->isValidSlug($slug) && $this->isThemeInstalled($slug),
        ));

        if ($command === null || $slugs === []) {
            return;
        }

        if ($this->wp($wpcli, $command, $slugs, mutating: true, errorBag: 'themes') === null) {
            return;
        }

        $this->selectedThemes = [];
        $this->toastSuccess(trans_choice('{1} 1 theme queued.|[2,*] :count themes queued.', count($slugs), ['count' => count($slugs)]));
    }

    public function toggleSelectAllThemes(): void
    {
        $all = array_map(static fn (array $t): string => (string) $t['name'], $this->themes);
        $this->selectedThemes = count($this->selectedThemes) === count($all) ? [] : $all;
    }

    /**
     * 6. Child theme — where customizations belong, so a parent update can't
     * overwrite them.
     *
     * Not activated: theme mods (customizer, menu locations) are stored per
     * theme, so switching would silently reset them. `scaffold` is not on
     * WpCli's recoverable allowlist, so this runs at the Destructive tier
     * (admin/owner) — the view gates the button the same way.
     */
    public function createChildTheme(string $parent, WpCli $wpcli): void
    {
        if (! $this->isValidSlug($parent) || ! $this->isThemeInstalled($parent)) {
            $this->addError('themes', __('Pick an installed theme.'));

            return;
        }

        $child = $parent.'-child';
        if ($this->isThemeInstalled($child)) {
            $this->addError('themes', __(':child already exists.', ['child' => $child]));

            return;
        }

        $title = (string) (collect($this->themes)->firstWhere('name', $parent)['title'] ?? '');
        $args = [$child, '--parent_theme='.$parent, '--theme_name='.($title !== '' ? $title : Str::headline($parent)).' Child'];
        if ($this->wp($wpcli, 'scaffold child-theme', $args, mutating: true, errorBag: 'themes') === null) {
            return;
        }

        $this->toastSuccess(__('Queued: child theme :child. Activate it once it appears — it starts with empty customizer settings.', ['child' => $child]));
    }

    /**
     * Tag directory rows with whether the site already has them; wp-cli's
     * list commands report the slug as `name`.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  list<array<string, mixed>>  $installed
     * @return list<array<string, mixed>>
     */
    private function markInstalled(array $items, array $installed): array
    {
        $slugs = array_column($installed, 'name');

        return array_map(
            static fn (array $p): array => $p + ['installed' => in_array((string) ($p['slug'] ?? ''), $slugs, true)],
            $items,
        );
    }

    private function isThemeInstalled(string $slug): bool
    {
        return $slug !== '' && collect($this->themes)->contains('name', $slug);
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
