<?php

declare(strict_types=1);

namespace App\Modules\Scaffold\Services;

use App\Livewire\Sites\ChooseApp;
use App\Models\ServerDatabase;
use App\Models\Site;
use App\Models\SiteAuditEvent;
use App\Notifications\SiteDatabaseCredentialsNotification;
use App\Modules\RemoteCli\Services\RiskLevel;
use App\Modules\RemoteCli\Services\SiteAuditWriter;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Servers\ServerDatabaseProvisioner;
use App\Services\Sites\AppCatalog;
use App\Modules\Scaffold\Support\DatabaseConnectionEnv;
use App\Support\Servers\InstalledStack;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Generic, recipe-driven scaffold pipeline for Composer-installable apps
 * (Statamic, Symfony, Craft, Drupal, …). Mirrors {@see ScaffoldLaravelPipeline}'s
 * proven machinery but parameterizes the package + post-install behaviour via a
 * recipe stashed on the Site at meta.scaffold.recipe by
 * {@see ChooseApp}. Adding a new auto-install is a catalog
 * entry in {@see AppCatalog} — no new pipeline class.
 *
 * Recipe shape (array):
 *   package           string  composer create-project target (e.g. "statamic/statamic")
 *   needs_db          bool    provision a DB + user (and, for env=laravel, write a DB block)
 *   env               string  'laravel' = ensure .env + key:generate; 'none' = leave as shipped
 *   migrate           bool    run `php artisan migrate --force` after install
 *   finish_in_browser bool    app has its own web installer (Craft/Drupal) — surfaced to the journey
 *
 * Steps: prereqs → placeholder_dns → [db_create] → composer_create → [write_env] → [migrate].
 * Each records to meta.scaffold.steps[] for the journey UI.
 */
class ScaffoldComposerPipeline
{
    public function __construct(
        private readonly ScaffoldPrerequisites $prerequisites,
        private readonly ServerDatabaseProvisioner $databaseProvisioner,
        private readonly ExecuteRemoteTaskOnServer $executor,
        private readonly SiteAuditWriter $audit,
        private readonly PlaceholderDnsManager $placeholderDns,
        private readonly ScaffoldRepoSeeder $repoSeeder,
    ) {}

    /**
     * @return array{ok: bool, failed_step: ?string, error: ?string}
     */
    /** @return array<string, mixed> */
    public function run(Site $site): array
    {
        $recipe = $this->recipe($site);
        $framework = (string) ($site->meta['scaffold']['framework'] ?? 'composer');
        $package = (string) ($recipe['package'] ?? '');
        if ($package === '') {
            $site->status = Site::STATUS_SCAFFOLD_FAILED;
            $site->save();

            return ['ok' => false, 'failed_step' => 'recipe', 'error' => 'No composer package configured for this app.'];
        }

        $needsDb = (bool) ($recipe['needs_db'] ?? false);
        $envStrategy = (string) ($recipe['env'] ?? 'none');
        $runMigrate = (bool) ($recipe['migrate'] ?? false);

        $this->initSteps($site, $package, $needsDb, $envStrategy, $runMigrate);

        $steps = [
            ['prereqs', fn () => $this->stepPrereqs($site)],
            ['placeholder_dns', fn () => $this->stepAssignPlaceholderDns($site)],
        ];
        if ($needsDb) {
            $steps[] = ['db_create', fn () => $this->stepCreateDatabase($site)];
        }
        $steps[] = ['composer_create', fn () => $this->stepComposerCreate($site, $package)];
        if ($envStrategy === 'laravel') {
            $steps[] = ['write_env', fn () => $this->stepWriteEnv($site, $needsDb)];
        }
        if ($runMigrate) {
            $steps[] = ['migrate', fn () => $this->stepMigrate($site)];
        }

        foreach ($steps as [$key, $fn]) {
            $this->markStep($site, $key, ScaffoldStep::STATE_RUNNING);
            try {
                $fn();
                $this->markStep($site, $key, ScaffoldStep::STATE_COMPLETED);
            } catch (Throwable $e) {
                Log::warning('Composer scaffold step failed', [
                    'site_id' => $site->getKey(),
                    'framework' => $framework,
                    'step' => $key,
                    'error' => $e->getMessage(),
                ]);
                $this->markStep($site, $key, ScaffoldStep::STATE_FAILED, error: $e->getMessage());
                $site->status = Site::STATUS_SCAFFOLD_FAILED;
                $site->save();

                $this->audit->record(
                    site: $site,
                    user: null,
                    action: 'scaffold_failed',
                    risk: RiskLevel::Destructive,
                    transport: SiteAuditEvent::TRANSPORT_SYSTEM,
                    summary: ucfirst($framework).' scaffold failed at step '.$key,
                    payload: ['framework' => $framework, 'package' => $package, 'step' => $key, 'error' => $e->getMessage()],
                    resultStatus: SiteAuditEvent::RESULT_FAILURE,
                );

                return ['ok' => false, 'failed_step' => $key, 'error' => $e->getMessage()];
            }
        }

        // Best-effort: seed a local Git repo for code-first scaffolds (Statamic /
        // Symfony / Craft — not Drupal, which is manage-in-place). A failure here
        // must never fail the scaffold: the site is already installed + live, so
        // we just log and leave it repo-less to connect one later.
        try {
            $this->repoSeeder->seed($site->fresh() ?? $site);
        } catch (Throwable $e) {
            Log::warning('Scaffold repo seed failed (non-fatal)', [
                'site_id' => $site->getKey(),
                'framework' => $framework,
                'error' => $e->getMessage(),
            ]);
        }

        $site = $site->fresh() ?? $site;
        $site->status = Site::STATUS_PENDING;
        $site->save();

        $this->audit->record(
            site: $site,
            user: null,
            action: 'scaffold_completed',
            risk: RiskLevel::MutatingRecoverable,
            transport: SiteAuditEvent::TRANSPORT_SYSTEM,
            summary: ucfirst($framework).' scaffold completed',
            payload: ['framework' => $framework, 'package' => $package],
            resultStatus: SiteAuditEvent::RESULT_SUCCESS,
        );

        return ['ok' => true, 'failed_step' => null, 'error' => null];
    }

    /**
     * @return array<string, mixed>
     */
    private function recipe(Site $site): array
    {
        $recipe = $site->meta['scaffold']['recipe'] ?? [];

        return is_array($recipe) ? $recipe : [];
    }

    private function initSteps(Site $site, string $package, bool $needsDb, string $envStrategy, bool $runMigrate): void
    {
        $steps = [
            ScaffoldStep::pending('prereqs', 'Verify prerequisites (composer)'),
            ScaffoldStep::pending('placeholder_dns', 'Assign placeholder hostname'),
        ];
        if ($needsDb) {
            $steps[] = ScaffoldStep::pending('db_create', 'Create database + user');
        }
        $steps[] = ScaffoldStep::pending('composer_create', 'composer create-project '.$package);
        if ($envStrategy === 'laravel') {
            $steps[] = ScaffoldStep::pending('write_env', 'Write .env and generate app key');
        }
        if ($runMigrate) {
            $steps[] = ScaffoldStep::pending('migrate', 'Run migrations');
        }
        $this->setMeta($site, 'scaffold.steps', $steps);
        $this->setMeta($site, 'scaffold.started_at', now()->toISOString());
    }

    private function stepPrereqs(Site $site): void
    {
        $result = $this->prerequisites->ensureComposer($site->server);
        if (! $result->ok()) {
            throw new \RuntimeException('Composer install failed: '.$result->error);
        }
    }

    private function stepAssignPlaceholderDns(Site $site): void
    {
        $assignment = $this->placeholderDns->assign($site);

        $hostname = $assignment['hostname'];
        if (! is_string($hostname) || $hostname === '') {
            throw new \RuntimeException('Placeholder DNS assignment returned no hostname.');
        }

        $existing = $site->domains()->where('hostname', $hostname)->first();
        if ($existing === null) {
            $site->domains()->create([
                'hostname' => $hostname,
                'is_primary' => $site->domains()->where('is_primary', true)->doesntExist(),
                'www_redirect' => false,
            ]);
            $site->flushPrimaryDomainCache();
        }
    }

    /**
     * Provision a Site DB + user. Lifted from {@see ScaffoldLaravelPipeline}
     * so non-Laravel apps that need a database (Craft, Drupal) get one created
     * + tracked + emailed identically. SQLite servers record the file path.
     */
    private function stepCreateDatabase(Site $site): void
    {
        $installed = InstalledStack::fromMeta($site->server);
        $engine = $installed->database ?? 'mysql84';

        if ($engine === 'sqlite3' || str_starts_with($engine, 'sqlite')) {
            $sqlitePath = $this->deployPath($site).'/database/database.sqlite';

            $db = new ServerDatabase([
                'server_id' => $site->server->id,
                'name' => 'dply_'.Str::slug($site->slug, '_'),
                'username' => '',
                'password' => '',
                'engine' => 'sqlite',
                'host' => $sqlitePath,
            ]);
            $db->save();

            $this->setMeta($site, 'scaffold.database', [
                'engine' => 'sqlite3',
                'server_database_id' => $db->id,
                'sqlite_path' => $sqlitePath,
            ]);

            $this->maybeEmailDatabaseCredentials($site, 'sqlite3', null, null, null, $sqlitePath);

            return;
        }

        $dbName = 'dply_'.Str::slug($site->slug, '_');
        $username = 'dply_'.Str::slug($site->slug, '_');
        // Idempotent on retry: a prior failed attempt may have already created
        // this server_databases row. Reuse it (keeping its stored password so
        // the server-side user and .env stay in sync) rather than inserting a
        // duplicate, which trips the (server_id, name) unique constraint.
        $db = ServerDatabase::firstOrNew([
            'server_id' => $site->server->id,
            'name' => $dbName,
        ]);
        if (! $db->exists) {
            $db->fill([
                'username' => $username,
                'password' => Str::password(24, symbols: false),
                'engine' => $engine,
                'host' => 'localhost',
            ])->save();
        }
        $password = $db->password;
        $this->databaseProvisioner->createOnServer($db);

        $this->setMeta($site, 'scaffold.database', [
            'engine' => $engine,
            'server_database_id' => $db->id,
            'name' => $dbName,
            'username' => $username,
            'password' => encrypt($password),
        ]);

        $this->maybeEmailDatabaseCredentials($site, $engine, $password, $dbName, $username, null);
    }

    private function maybeEmailDatabaseCredentials(
        Site $site,
        string $engine,
        ?string $password,
        ?string $databaseName,
        ?string $username,
        ?string $sqlitePath,
    ): void {
        $organization = $site->organization;
        $creator = $site->user;

        if (! $organization || ! $organization->email_database_credentials_enabled) {
            return;
        }
        if (! $creator || ! filled($creator->email)) {
            return;
        }

        $creator->notify(new SiteDatabaseCredentialsNotification(
            site: $site,
            engine: $engine,
            password: $password,
            databaseName: $databaseName,
            username: $username,
            sqlitePath: $sqlitePath,
        ));
    }

    private function stepComposerCreate(Site $site, string $package): void
    {
        $deployPath = $this->deployPath($site);
        $cmd = sprintf(
            'sudo -u dply mkdir -p %s && cd %s && composer create-project %s . --no-interaction --no-progress',
            escapeshellarg($deployPath),
            escapeshellarg($deployPath),
            escapeshellarg($package),
        );
        $out = $this->executor->runInlineBash(
            server: $site->server,
            name: 'scaffold-composer:create',
            inlineBash: $cmd,
            timeoutSeconds: 600,
        );
        if ($out->getExitCode() !== 0) {
            throw new \RuntimeException('composer create-project failed: '.$out->getBuffer());
        }
    }

    /**
     * Laravel-style env bootstrap: ensure a .env exists (copy from
     * .env.example when the package ships one), optionally append a DB
     * block, then generate the app key. Used for Laravel-family apps
     * (Statamic). Apps with their own .env schema use env=none.
     */
    private function stepWriteEnv(Site $site, bool $needsDb): void
    {
        $deployPath = $this->deployPath($site);

        $dbBlock = '';
        if ($needsDb) {
            $db = $site->fresh()->meta['scaffold']['database'] ?? [];
            $engine = (string) ($db['engine'] ?? 'mysql84');
            if ($engine === 'sqlite3' || str_starts_with($engine, 'sqlite')) {
                $dbBlock = trim(DatabaseConnectionEnv::forEngine('sqlite3', [
                    'sqlite_path' => (string) ($db['sqlite_path'] ?? $deployPath.'/database/database.sqlite'),
                ]));
            } else {
                $dbBlock = trim(DatabaseConnectionEnv::forEngine($engine, [
                    'name' => (string) ($db['name'] ?? ''),
                    'username' => (string) ($db['username'] ?? ''),
                    'password' => isset($db['password']) ? decrypt($db['password']) : '',
                ]));
            }
        }

        $appendDb = $dbBlock !== ''
            ? sprintf(' && printf %s >> .env', escapeshellarg("\n".$dbBlock."\n"))
            : '';

        $cmd = sprintf(
            'cd %s && { [ -f .env ] || { [ -f .env.example ] && cp .env.example .env; }; }%s && php artisan key:generate --force',
            escapeshellarg($deployPath),
            $appendDb,
        );

        $out = $this->executor->runInlineBash(
            server: $site->server,
            name: 'scaffold-composer:write-env',
            inlineBash: $cmd,
            timeoutSeconds: 60,
        );
        if ($out->getExitCode() !== 0) {
            throw new \RuntimeException('write env failed: '.$out->getBuffer());
        }
    }

    private function stepMigrate(Site $site): void
    {
        $deployPath = $this->deployPath($site);
        $out = $this->executor->runInlineBash(
            server: $site->server,
            name: 'scaffold-composer:migrate',
            inlineBash: sprintf('cd %s && php artisan migrate --force', escapeshellarg($deployPath)),
            timeoutSeconds: 120,
        );
        if ($out->getExitCode() !== 0) {
            throw new \RuntimeException('migrate failed: '.$out->getBuffer());
        }
    }

    private function deployPath(Site $site): string
    {
        return '/home/dply/'.$site->slug.'/current';
    }

    private function setMeta(Site $site, string $dottedPath, mixed $value): void
    {
        $site = $site->fresh() ?? $site;
        $meta = ($site->meta );
        data_set($meta, $dottedPath, $value);
        $site->meta = $meta;
        $site->save();
    }

    private function markStep(Site $site, string $key, string $state, ?string $error = null): void
    {
        $site = $site->fresh() ?? $site;
        $meta = ($site->meta );
        $steps = $meta['scaffold']['steps'] ?? [];
        foreach ($steps as &$step) {
            if (($step['key'] ?? null) === $key) {
                $step['state'] = $state;
                if ($error !== null) {
                    $step['error'] = $error;
                }
                if ($state === ScaffoldStep::STATE_RUNNING) {
                    $step['started_at'] = now()->toISOString();
                }
                if (in_array($state, [ScaffoldStep::STATE_COMPLETED, ScaffoldStep::STATE_FAILED], true)) {
                    $step['finished_at'] = now()->toISOString();
                }
                break;
            }
        }
        $meta['scaffold']['steps'] = $steps;
        $site->meta = $meta;
        $site->save();
    }
}
