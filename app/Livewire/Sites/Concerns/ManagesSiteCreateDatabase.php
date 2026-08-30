<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Jobs\CreateSiteDatabaseJob;
use App\Livewire\Sites\Database;
use App\Models\ConsoleAction;
use App\Models\ServerDatabase;
use App\Models\Site;
use App\Support\Servers\DatabaseWorkspaceEngines;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * "Give the new site a database" — the create-form half of the site/database
 * pairing that previously had to be done by hand on the Database tab after
 * provisioning.
 *
 * Shape of the ask:
 *   - The server exposes zero engines (cache-only box, container host, static
 *     site) → the whole section is hidden and nothing is created.
 *   - Exactly one engine → no engine question; it is simply used.
 *   - More than one → the engine picker is surfaced (it already was) alongside
 *     the name field, so the user says which engine AND what to call the DB.
 *
 * The name field is always shown when the section is, pre-filled from the site
 * name. A single-engine server therefore still asks nothing it doesn't have to:
 * the suggestion is a valid answer the user can ignore.
 *
 * Creation itself mirrors {@see Database::createDatabase()}
 * exactly — same credential generation, same sqlite path handling, same
 * {@see CreateSiteDatabaseJob} with write-env + push-env on, which is what
 * "auto connect it" means: DB_* lands in the site's .env and is pushed to the
 * box. Slow work stays in the queued job; nothing here touches SSH.
 */
trait ManagesSiteCreateDatabase
{
    /**
     * Populate {@see $availableDatabaseEngines} and the create-a-database
     * defaults from the target server. Called from mount().
     */
    protected function initializeDatabaseDefaults(): void
    {
        $engines = $this->server->databaseEngines()->orderBy('engine')->get();

        $this->availableDatabaseEngines = $engines->map(fn ($e) => [
            'id' => (string) $e->engine,
            'label' => trim((string) $e->engine.' '.($e->version ?? '')),
        ])->values()->all();

        $default = $engines->firstWhere('is_default', true) ?? $engines->first();
        if ($default !== null && $this->form->database_engine === '') {
            $this->form->database_engine = (string) $default->engine;
        }

        // Nothing to offer on a server with no engines — keep the toggle off so
        // store() short-circuits even if the property is posted back.
        $this->form->create_database = $this->availableDatabaseEngines !== [];
    }

    /**
     * Whether the create form should render the database section at all.
     * Static sites don't get one, and neither do hosts we can't provision a
     * server-local database on (container / Kubernetes / serverless).
     */
    public function databaseCreationAvailable(): bool
    {
        return $this->availableDatabaseEngines !== []
            && $this->form->type !== 'static'
            && ! $this->isContainerMode()
            && ! $this->server->isServerlessHost();
    }

    /**
     * Keep the suggested database name in step with the site name until the
     * user edits it themselves. Once they do, {@see updatedFormDatabaseName()}
     * latches and we stop overwriting their choice.
     */
    public function updatedFormName(string $value): void
    {
        if ($this->databaseNameTouched) {
            return;
        }

        $this->form->database_name = self::sanitizeDatabaseName($value);
    }

    public function updatedFormDatabaseName(string $value): void
    {
        $this->databaseNameTouched = true;
        $this->form->database_name = self::sanitizeDatabaseName($value);
    }

    /**
     * Validation rules merged into the store() rule set. Empty when the
     * section isn't in play, so a server with no engines validates as before.
     *
     * @return array<string, mixed>
     */
    protected function databaseCreationRules(): array
    {
        if (! $this->databaseCreationAvailable() || ! $this->form->create_database) {
            return [];
        }

        return [
            'database_name' => [
                'required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9_]+$/',
                Rule::unique('server_databases', 'name')->where('server_id', $this->server->id),
            ],
            'database_engine' => [
                'required',
                Rule::in(array_column($this->availableDatabaseEngines, 'id')),
            ],
        ];
    }

    /**
     * Create the ServerDatabase row for a freshly-stored site and queue the
     * provisioning + .env wiring. No-op when the user opted out or the server
     * has nothing to provision on.
     */
    protected function provisionInitialDatabase(Site $site): void
    {
        if (! $this->databaseCreationAvailable() || ! $this->form->create_database) {
            return;
        }

        $engine = $this->form->database_engine;
        $name = self::sanitizeDatabaseName($this->form->database_name);
        if ($engine === '' || $name === '') {
            return;
        }

        $db = ServerDatabase::query()->create(
            $this->initialDatabaseAttributes($site, $engine, $name),
        );

        // Seed the console run up-front so the site workspace's Database tab
        // has live output to attach to the moment the user lands on it.
        $run = ConsoleAction::query()->create([
            'subject_type' => $site->getMorphClass(),
            'subject_id' => $site->id,
            'kind' => 'site_db_create',
            'status' => ConsoleAction::STATUS_QUEUED,
            'label' => __('Create :engine database :name', [
                'engine' => DatabaseWorkspaceEngines::label($engine),
                'name' => $name,
            ]),
            'user_id' => auth()->id(),
            'output' => ['v' => (int) config('console_actions.current_version', 1), 'lines' => []],
        ]);

        // write-env, but do NOT push: the DB_* vars land in the site's env
        // cache immediately (which is what "connect it" means here — the site
        // knows how to reach its database from the moment it exists), and the
        // first deploy writes them to the box. Pushing now would race
        // ProvisionSiteJob, which runs on a different queue and hasn't laid
        // out the site directory yet; the push would sudo-mkdir it as root
        // ahead of the provisioner.
        CreateSiteDatabaseJob::dispatch(
            $db->id,
            $site->id,
            true,
            false,
            (string) (auth()->id() ?? ''),
            (string) $run->id,
        );
    }

    /**
     * Row attributes for the new database, mirroring the Database tab's
     * create flow (generated credentials; sqlite is a file path, not a daemon).
     *
     * @return array<string, mixed>
     */
    private function initialDatabaseAttributes(Site $site, string $engine, string $name): array
    {
        if (DatabaseWorkspaceEngines::family($engine) === 'sqlite') {
            $root = rtrim((string) config('server_database.sqlite_root', '/var/lib/dply/sqlite'), '/');

            return [
                'server_id' => $site->server_id,
                'site_id' => $site->id,
                'name' => $name,
                'engine' => $engine,
                'username' => '',
                'password' => '',
                'host' => $root.'/'.$site->server_id.'/'.$name.'.db',
            ];
        }

        $base = Str::slug($name, '_') ?: 'db';

        return [
            'server_id' => $site->server_id,
            'site_id' => $site->id,
            'name' => $name,
            'engine' => $engine,
            'username' => Str::limit($base, 28, '').'_'.Str::lower(Str::random(4)),
            'password' => ServerDatabase::generateConnectionSafePassword(),
            'host' => '127.0.0.1',
        ];
    }

    /**
     * Same sanitizer the server- and site-level database managers use: a
     * database identifier is lowercase [a-z0-9_], no leading/trailing/doubled
     * underscores, 64 chars max.
     */
    private static function sanitizeDatabaseName(string $value): string
    {
        $value = strtolower($value);
        $value = preg_replace('/[\s.\-]+/', '_', $value) ?? '';
        $value = preg_replace('/[^a-z0-9_]/', '', $value) ?? '';
        $value = preg_replace('/_+/', '_', $value) ?? '';

        return substr(trim($value, '_'), 0, 64);
    }
}
