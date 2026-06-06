<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Jobs\CreateSiteDatabaseJob;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\WatchesConsoleActionOutcomes;
use App\Models\ConsoleAction;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\ServerDatabaseAuditEvent;
use App\Models\ServerDatabaseCredentialShare;
use App\Models\Site;
use App\Services\Servers\ServerDatabaseAuditLogger;
use App\Support\Servers\DatabaseWorkspaceEngines;
use App\Support\Servers\ServerDatabaseHostCapabilities;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Site-scoped database tab (VM sites only).
 *
 * A focused complement to the server-level {@see \App\Livewire\Servers\WorkspaceDatabases}
 * manager: list the databases that belong to this site, create a new one
 * (auto-named after the site, with optional .env wiring), link an existing
 * unlinked server database, or detach one. Heavy operations — drop, backup,
 * extra users, engine install — stay on the server-level manager.
 *
 * Create runs through {@see CreateSiteDatabaseJob} (never inline — SSH must be
 * queued); the page watches the seeded ConsoleAction for the result.
 */
#[Layout('layouts.app')]
class Database extends Component
{
    use AuthorizesRequests;
    use DispatchesToastNotifications;
    use WatchesConsoleActionOutcomes;

    public Server $server;

    public Site $site;

    /** Create form. */
    public string $new_db_name = '';

    public string $new_db_engine = '';

    public string $new_db_username = '';

    public string $new_db_password = '';

    public string $new_mysql_charset = '';

    public string $new_mysql_collation = '';

    public string $new_db_description = '';

    public bool $write_env = true;

    public bool $push_env = false;

    /** Link-existing form. */
    public string $link_database_id = '';

    /** Credential-share modal. */
    public ?string $share_link_url = null;

    public ?string $share_link_db_name = null;

    public function mount(Server $server, Site $site): void
    {
        $this->authorize('view', $site);

        // VM sites only — Cloud/Edge/container workspaces manage databases
        // through their own surfaces (CloudDatabase, etc.). The sidebar hides
        // this tab for them, but guard the direct route hit too.
        abort_unless($site->runtimeTargetMode() === 'vm', 404);

        $this->server = $server;
        $this->site = $site;

        $this->new_db_name = $this->suggestedName();
        $this->new_db_engine = $this->defaultEngine();
    }

    /**
     * @return array{mysql: bool, mariadb: bool, postgres: bool, mongodb: bool, clickhouse: bool, sqlite: bool}
     */
    #[Computed]
    public function capabilities(): array
    {
        return app(ServerDatabaseHostCapabilities::class)->forServer($this->server);
    }

    /**
     * Engine slugs installed and reachable on the server, in tab order.
     *
     * @return list<string>
     */
    #[Computed]
    public function installedEngines(): array
    {
        $caps = $this->capabilities();

        return array_values(array_filter(
            DatabaseWorkspaceEngines::ENGINE_TABS,
            fn (string $engine): bool => $caps[$engine] ?? false,
        ));
    }

    /**
     * Databases owned by this site.
     *
     * @return \Illuminate\Support\Collection<int, ServerDatabase>
     */
    #[Computed]
    public function linkedDatabases()
    {
        return $this->site->serverDatabases()->get();
    }

    /**
     * Server databases not yet attached to any site — candidates for linking.
     *
     * @return \Illuminate\Support\Collection<int, ServerDatabase>
     */
    #[Computed]
    public function linkableDatabases()
    {
        return ServerDatabase::query()
            ->where('server_id', $this->server->id)
            ->whereNull('site_id')
            ->orderBy('name')
            ->get();
    }

    public function updatedNewDbName(string $value): void
    {
        // Mirror the server-level sanitizer: a database identifier is
        // [a-z0-9_], lowercase, no leading/trailing/doubled underscores.
        $value = strtolower($value);
        $value = preg_replace('/[\s.\-]+/', '_', $value) ?? '';
        $value = preg_replace('/[^a-z0-9_]/', '', $value) ?? '';
        $value = preg_replace('/_+/', '_', $value) ?? '';
        $this->new_db_name = substr(trim($value, '_'), 0, 64);
    }

    public function createDatabase(ServerDatabaseAuditLogger $auditLogger): void
    {
        $this->authorize('update', $this->site);

        $this->new_db_username = trim($this->new_db_username);

        if (! in_array($this->new_db_engine, $this->installedEngines(), true)) {
            $this->addError('new_db_engine', __(':engine is not installed on this server.', [
                'engine' => DatabaseWorkspaceEngines::label($this->new_db_engine),
            ]));

            return;
        }

        $isSqlite = DatabaseWorkspaceEngines::family($this->new_db_engine) === 'sqlite';

        $rules = [
            'new_db_name' => [
                'required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9_]+$/',
                Rule::unique('server_databases', 'name')->where('server_id', $this->server->id),
            ],
            'new_db_engine' => 'required|in:'.implode(',', DatabaseWorkspaceEngines::ENGINE_TABS),
            'new_db_description' => 'nullable|string|max:2000',
            'new_mysql_charset' => 'nullable|string|max:64|regex:/^[a-zA-Z0-9_]*$/',
            'new_mysql_collation' => 'nullable|string|max:64|regex:/^[a-zA-Z0-9_]*$/',
            'new_db_username' => $isSqlite ? 'nullable' : 'nullable|string|max:64|regex:/^[a-zA-Z0-9_]+$/',
            'new_db_password' => $this->new_db_password !== '' ? 'string|max:200' : 'nullable',
        ];

        $this->validate($rules, [
            'new_db_name.unique' => __('A database named :name is already tracked on this server.', ['name' => $this->new_db_name]),
        ], [
            'new_db_name' => __('Name'),
            'new_db_engine' => __('Engine'),
        ]);

        // Resolve credentials (auto-generate when blank), mirroring the
        // server-level create flow so the two surfaces behave identically.
        $username = $this->new_db_username;
        if (! $isSqlite && $username === '') {
            $base = \Illuminate\Support\Str::slug($this->new_db_name, '_') ?: 'db';
            $username = \Illuminate\Support\Str::limit($base, 28, '').'_'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(4));
        }

        $password = $this->new_db_password;
        if (! $isSqlite && $password === '') {
            $password = ServerDatabase::generateConnectionSafePassword();
        }

        $host = '127.0.0.1';
        if ($isSqlite) {
            $root = rtrim((string) config('server_database.sqlite_root', '/var/lib/dply/sqlite'), '/');
            $host = $root.'/'.$this->server->id.'/'.$this->new_db_name.'.db';
            $username = '';
            $password = '';
        }

        $db = ServerDatabase::query()->create([
            'server_id' => $this->server->id,
            'site_id' => $this->site->id,
            'name' => $this->new_db_name,
            'engine' => $this->new_db_engine,
            'username' => $username,
            'password' => $password,
            'host' => $host,
            'description' => $this->new_db_description ?: null,
            'mysql_charset' => $this->new_mysql_charset ?: null,
            'mysql_collation' => $this->new_mysql_collation ?: null,
        ]);

        // Hand back a one-time credential-share link instead of holding the
        // plaintext password in component state. The link reads the encrypted
        // row, so it works the moment the row exists — no need to wait on the
        // background provisioning to finish.
        if (! $isSqlite) {
            $this->issueCredentialShare($db, $auditLogger);
        }

        $run = $this->seedConsoleRun('site_db_create', __('Create :engine database :name', [
            'engine' => DatabaseWorkspaceEngines::label($db->engine),
            'name' => $db->name,
        ]));

        CreateSiteDatabaseJob::dispatch(
            $db->id,
            $this->site->id,
            $this->write_env,
            $this->write_env && $this->push_env,
            (string) (auth()->id() ?? ''),
            (string) $run->id,
        );

        $this->watchConsoleAction(
            $run,
            __('Database :name created.', ['name' => $db->name]),
            __('Database creation did not finish.'),
        );
        $this->dispatch('dply-console-action-focus');
        $this->toastConsoleActionQueued();

        $this->resetCreateForm();

        if ($this->share_link_url !== null) {
            $this->dispatch('open-modal', 'site-db-credentials-modal');
        }

        unset($this->linkedDatabases, $this->linkableDatabases);
    }

    public function linkDatabase(): void
    {
        $this->authorize('update', $this->site);
        $this->validate([
            'link_database_id' => 'required|ulid',
        ]);

        // Only adopt databases that aren't already owned by another site —
        // the dropdown only lists unlinked ones, but re-check on submit so a
        // concurrent link can't silently steal a database from another site.
        $db = ServerDatabase::query()
            ->where('server_id', $this->server->id)
            ->whereNull('site_id')
            ->find($this->link_database_id);

        if (! $db instanceof ServerDatabase) {
            $this->addError('link_database_id', __('That database is no longer available to link.'));

            return;
        }

        $db->forceFill(['site_id' => $this->site->id])->save();
        $this->link_database_id = '';
        unset($this->linkedDatabases, $this->linkableDatabases);
        $this->toastSuccess(__('Linked :name to this site.', ['name' => $db->name]));
    }

    public function unlinkDatabase(string $id): void
    {
        $this->authorize('update', $this->site);

        $db = ServerDatabase::query()
            ->where('server_id', $this->server->id)
            ->where('site_id', $this->site->id)
            ->find($id);

        if (! $db instanceof ServerDatabase) {
            return;
        }

        // Detach only — the database stays on the server untouched. Dropping it
        // is an explicit action on the server-level manager.
        $db->forceFill(['site_id' => null])->save();
        unset($this->linkedDatabases, $this->linkableDatabases);
        $this->toastSuccess(__('Detached :name. The database was not dropped on the server.', ['name' => $db->name]));
    }

    public function render(): View
    {
        return view('livewire.sites.database', [
            'consoleRun' => $this->latestConsoleRun(),
        ]);
    }

    private function issueCredentialShare(ServerDatabase $db, ServerDatabaseAuditLogger $auditLogger): void
    {
        $org = $this->server->organization;
        if ($org && ! $org->allowsDatabaseCredentialShares()) {
            return;
        }

        $token = \Illuminate\Support\Str::random(48);
        ServerDatabaseCredentialShare::query()->create([
            'server_database_id' => $db->id,
            'user_id' => auth()->id(),
            'token' => $token,
            'expires_at' => now()->addHours((int) config('server_database.credential_share_expires_hours', 72)),
            'views_remaining' => (int) config('server_database.credential_share_max_views', 3),
            'max_views' => (int) config('server_database.credential_share_max_views', 3),
        ]);

        $auditLogger->record($this->server, ServerDatabaseAuditEvent::EVENT_CREDENTIAL_SHARE_CREATED, [
            'server_database_id' => $db->id,
        ], auth()->user());

        $this->share_link_url = route('database-credential-shares.show', ['token' => $token]);
        $this->share_link_db_name = $db->name;
    }

    private function latestConsoleRun(): ?ConsoleAction
    {
        return ConsoleAction::query()
            ->forSubject($this->site)
            ->ofKind('site_db_create')
            ->notDismissed()
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * Seed a queued ConsoleAction on the site, superseding stale ones, so the
     * banner has a row to render before the worker picks the job up. Mirrors
     * {@see \App\Livewire\Sites\Show::seedQueuedConsoleAction()}.
     */
    private function seedConsoleRun(string $kind, ?string $label = null): ConsoleAction
    {
        ConsoleAction::query()
            ->forSubject($this->site)
            ->notDismissed()
            ->whereIn('status', [ConsoleAction::STATUS_COMPLETED, ConsoleAction::STATUS_FAILED])
            ->update(['dismissed_at' => now()]);

        return ConsoleAction::query()->create([
            'subject_type' => $this->site->getMorphClass(),
            'subject_id' => $this->site->id,
            'kind' => $kind,
            'status' => ConsoleAction::STATUS_QUEUED,
            'label' => $label,
            'user_id' => auth()->id(),
            'output' => ['v' => (int) config('console_actions.current_version', 1), 'lines' => []],
        ]);
    }

    private function resetCreateForm(): void
    {
        $this->new_db_name = '';
        $this->new_db_username = '';
        $this->new_db_password = '';
        $this->new_mysql_charset = '';
        $this->new_mysql_collation = '';
        $this->new_db_description = '';
        $this->new_db_engine = $this->defaultEngine();
    }

    private function suggestedName(): string
    {
        $base = \Illuminate\Support\Str::slug((string) $this->site->name, '_') ?: 'app';

        return substr($base, 0, 64);
    }

    private function defaultEngine(): string
    {
        $installed = $this->installedEngines();
        if ($installed === []) {
            return '';
        }

        $resolved = $this->site->databaseEngine();
        $family = $resolved !== null ? DatabaseWorkspaceEngines::family($resolved) : null;

        if ($family !== null && in_array($family, $installed, true)) {
            return $family;
        }

        return $installed[0];
    }
}
