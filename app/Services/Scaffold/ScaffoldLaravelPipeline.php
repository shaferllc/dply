<?php

declare(strict_types=1);

namespace App\Services\Scaffold;

use App\Models\Site;
use App\Models\SiteAuditEvent;
use App\Models\Snapshot as SiteSnapshot;
use App\Services\RemoteCli\RiskLevel;
use App\Services\RemoteCli\SiteAuditWriter;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Servers\ServerDatabaseProvisioner;
use App\Support\Scaffold\DatabaseConnectionEnv;
use App\Support\Servers\InstalledStack;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Orchestrates the Laravel scaffold pipeline (Q11).
 *
 * Steps, in order:
 *   1. prereqs            — wp-cli/composer self-heal via ScaffoldPrerequisites
 *   2. db_create          — auto-provision Site DB + user via ServerDatabaseProvisioner
 *   3. composer_create    — composer create-project laravel/laravel into the deploy dir
 *   4. breeze_install     — composer require breeze + breeze:install blade --no-interaction
 *   5. write_env          — .env with APP_NAME / APP_KEY / DB credentials
 *   6. migrate            — php artisan migrate --force (creates Breeze users table)
 *   7. seed_admin         — first-user creation with the form's admin email + generated password
 *   8. supervisor_worker  — Supervisor entry for the queue worker (zero-cost at idle)
 *   9. apply_hardening    — opinionated-secure defaults (ScaffoldHardening, PR 6+)
 *  10. nginx_reload       — vhost include + reload
 *
 * Each step records to meta.scaffold.steps[] so the journey UI (PR 7)
 * can render progress without needing its own state model.
 *
 * v1 stops short of nginx vhost wiring + ScaffoldHardening — those land
 * alongside the WordPress pipeline (PR 6) which shares the same
 * hardening primitive shape. v1's "scaffold complete" is "code on disk
 * + DB created + .env written + migrations run + admin user seeded".
 * The journey shows the live URL as soon as nginx wiring is in.
 */
class ScaffoldLaravelPipeline
{
    public const FRAMEWORK = 'laravel';

    public function __construct(
        private readonly ScaffoldPrerequisites $prerequisites,
        private readonly ServerDatabaseProvisioner $databaseProvisioner,
        private readonly ExecuteRemoteTaskOnServer $executor,
        private readonly SiteAuditWriter $audit,
        private readonly PlaceholderDnsManager $placeholderDns,
    ) {}

    /**
     * @return array{ok: bool, failed_step: ?string, error: ?string}
     */
    public function run(Site $site): array
    {
        $this->initSteps($site);

        // Stash the generated admin password on meta so the journey
        // success screen (PR 7) can render it once. Encrypted-at-rest
        // via Laravel's encrypt() (the meta column is a JSON cast, not
        // an encrypted cast — wrap explicitly).
        $adminPassword = Str::password(20);
        $this->setMeta($site, 'scaffold.admin_password', encrypt($adminPassword));

        $steps = [
            ['prereqs', fn () => $this->stepPrereqs($site)],
            ['placeholder_dns', fn () => $this->stepAssignPlaceholderDns($site)],
            ['db_create', fn () => $this->stepCreateDatabase($site)],
            ['composer_create', fn () => $this->stepComposerCreate($site)],
            ['breeze_install', fn () => $this->stepBreezeInstall($site)],
            ['write_env', fn () => $this->stepWriteEnv($site)],
            ['migrate', fn () => $this->stepMigrate($site)],
            ['seed_admin', fn () => $this->stepSeedAdmin($site, $adminPassword)],
            ['supervisor_worker', fn () => $this->stepSupervisorWorker($site)],
        ];

        foreach ($steps as [$key, $fn]) {
            $this->markStep($site, $key, ScaffoldStep::STATE_RUNNING);
            try {
                $fn();
                $this->markStep($site, $key, ScaffoldStep::STATE_COMPLETED);
            } catch (Throwable $e) {
                Log::warning('Laravel scaffold step failed', [
                    'site_id' => $site->getKey(),
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
                    summary: 'Laravel scaffold failed at step '.$key,
                    payload: ['framework' => self::FRAMEWORK, 'step' => $key, 'error' => $e->getMessage()],
                    resultStatus: SiteAuditEvent::RESULT_FAILURE,
                );

                return ['ok' => false, 'failed_step' => $key, 'error' => $e->getMessage()];
            }
        }

        // Pipeline succeeded — flip status. nginx wiring + hardening
        // are intentionally deferred to a follow-up PR; the site row
        // is already viable and points at meta.scaffold.steps for
        // the journey to render.
        $site->status = Site::STATUS_PENDING;
        $site->save();

        $this->audit->record(
            site: $site,
            user: null,
            action: 'scaffold_completed',
            risk: RiskLevel::MutatingRecoverable,
            transport: SiteAuditEvent::TRANSPORT_SYSTEM,
            summary: 'Laravel scaffold completed',
            payload: ['framework' => self::FRAMEWORK],
            resultStatus: SiteAuditEvent::RESULT_SUCCESS,
        );

        return ['ok' => true, 'failed_step' => null, 'error' => null];
    }

    private function initSteps(Site $site): void
    {
        $steps = [
            ScaffoldStep::pending('prereqs', 'Verify prerequisites (composer)'),
            ScaffoldStep::pending('placeholder_dns', 'Assign placeholder hostname'),
            ScaffoldStep::pending('db_create', 'Create database + user'),
            ScaffoldStep::pending('composer_create', 'composer create-project laravel/laravel'),
            ScaffoldStep::pending('breeze_install', 'Install Breeze (Blade)'),
            ScaffoldStep::pending('write_env', 'Write .env with credentials'),
            ScaffoldStep::pending('migrate', 'Run migrations'),
            ScaffoldStep::pending('seed_admin', 'Seed admin user'),
            ScaffoldStep::pending('supervisor_worker', 'Provision queue worker'),
        ];
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

    /**
     * Assign a placeholder hostname (e.g. <slug>.ondply.io with nip.io
     * fallback) and persist it as a primary SiteDomain so downstream
     * pipeline steps + the rest of the codebase find it via
     * Site::primaryDomain(). Idempotent — safe to call again on retry
     * because PlaceholderDnsManager::assign() short-circuits when an
     * assignment already exists, and we only insert a SiteDomain when
     * one doesn't already exist for this hostname.
     */
    private function stepAssignPlaceholderDns(Site $site): void
    {
        $assignment = $this->placeholderDns->assign($site);

        $hostname = $assignment['hostname'] ?? null;
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
        }
    }

    private function stepCreateDatabase(Site $site): void
    {
        // Read what was *physically installed* on the server, not what
        // the wizard requested. On low-memory droplets the script falls
        // back from MySQL/Postgres to SQLite — using wizard meta here
        // would generate a ServerDatabase row pointing at a daemon that
        // doesn't exist, and the provisioner call below would fail.
        $installed = InstalledStack::fromMeta($site->server);
        $engine = $installed->database ?? 'mysql84';

        // SQLite has no users / host / port / credentials concept, and
        // ServerDatabaseProvisioner has no SQLite branch (it would
        // attempt mysql commands against a daemon that isn't installed).
        // Skip the row + provisioner entirely — Laravel's `php artisan
        // migrate` auto-creates the file from the DB_DATABASE env value
        // we'll write later. The scaffold meta still records enough for
        // the .env writer to find the path.
        if ($engine === 'sqlite3' || str_starts_with($engine, 'sqlite')) {
            $sqlitePath = $this->deployPath($site).'/database/database.sqlite';
            $this->setMeta($site, 'scaffold.database', [
                'engine' => 'sqlite3',
                'sqlite_path' => $sqlitePath,
            ]);

            // Surface the substitution prominently so anyone watching
            // the scaffolding output understands their MySQL/Postgres
            // request was overridden at provisioning time. Tagged-line
            // pattern matches the existing notice infrastructure.
            if ($installed->divergesFromRequest($site->server)) {
                $requested = $site->server->meta['database'] ?? 'unknown';
                Log::info('site.scaffold.notice', [
                    'site_id' => $site->id,
                    'reason' => 'database-engine-substituted',
                    'requested' => $requested,
                    'installed' => $engine,
                    'low_mem_mode' => $installed->lowMemoryMode,
                ]);
            }

            $this->maybeEmailDatabaseCredentials($site, 'sqlite3', null, null, null, $sqlitePath);

            return;
        }

        $dbName = 'dply_'.Str::slug($site->slug, '_');
        $username = 'dply_'.Str::slug($site->slug, '_');
        $password = Str::password(24, symbols: false);

        $db = new \App\Models\ServerDatabase([
            'server_id' => $site->server->id,
            'name' => $dbName,
            'username' => $username,
            'password' => $password,
            'engine' => $engine,
            'host' => 'localhost',
        ]);
        $db->save();
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

    /**
     * Email the database credentials to the site's creator IF the org
     * has the toggle on. Plain-text password is included in the email
     * body for SQL engines (the operator opted in for the convenience —
     * password-by-email is industry-standard for managed-DB workflows).
     * SQLite emails carry the file path instead.
     *
     * No-op when the org toggle is off, when the site has no creator,
     * or when the creator has no email — fail-closed defaults.
     */
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

        $creator->notify(new \App\Notifications\SiteDatabaseCredentialsNotification(
            site: $site,
            engine: $engine,
            password: $password,
            databaseName: $databaseName,
            username: $username,
            sqlitePath: $sqlitePath,
        ));
    }

    private function stepComposerCreate(Site $site): void
    {
        $deployPath = $this->deployPath($site);
        $cmd = sprintf(
            'sudo -u dply mkdir -p %s && cd %s && composer create-project laravel/laravel . --no-interaction --no-progress',
            escapeshellarg($deployPath),
            escapeshellarg($deployPath),
        );
        $out = $this->executor->runInlineBash(
            server: $site->server,
            name: 'scaffold-laravel:composer-create',
            inlineBash: $cmd,
            timeoutSeconds: 300,
        );
        if ($out->getExitCode() !== 0) {
            throw new \RuntimeException('composer create-project failed: '.$out->getBuffer());
        }
    }

    private function stepBreezeInstall(Site $site): void
    {
        $deployPath = $this->deployPath($site);
        $cmd = sprintf(
            'cd %s && composer require laravel/breeze --dev --no-interaction --no-progress && php artisan breeze:install blade --no-interaction',
            escapeshellarg($deployPath),
        );
        $out = $this->executor->runInlineBash(
            server: $site->server,
            name: 'scaffold-laravel:breeze-install',
            inlineBash: $cmd,
            timeoutSeconds: 300,
        );
        if ($out->getExitCode() !== 0) {
            throw new \RuntimeException('breeze install failed: '.$out->getBuffer());
        }
    }

    private function stepWriteEnv(Site $site): void
    {
        $deployPath = $this->deployPath($site);
        $db = $site->fresh()->meta['scaffold']['database'];
        $appName = addslashes($site->name);

        // Build the DB block from a single helper so adding engines
        // doesn't touch this file. Engine-specific branching used to
        // be a hardcoded `DB_CONNECTION=mysql` here, which silently
        // produced broken Laravel apps the moment the wizard or the
        // provisioning script picked anything other than mysql.
        $engine = (string) ($db['engine'] ?? 'mysql84');
        if ($engine === 'sqlite3' || str_starts_with($engine, 'sqlite')) {
            $dbBlock = trim(DatabaseConnectionEnv::forEngine('sqlite3', [
                'sqlite_path' => (string) ($db['sqlite_path'] ?? $deployPath.'/database/database.sqlite'),
            ]));
        } else {
            $dbBlock = trim(DatabaseConnectionEnv::forEngine($engine, [
                'name' => (string) $db['name'],
                'username' => (string) $db['username'],
                'password' => decrypt($db['password']),
            ]));
        }

        $envBody = <<<ENV
        APP_NAME="{$appName}"
        APP_ENV=production
        APP_KEY=
        APP_DEBUG=false
        APP_URL={$this->appUrl($site)}

        LOG_CHANNEL=daily
        LOG_LEVEL=info

        {$dbBlock}
        ENV;

        $cmd = sprintf(
            'cd %s && cat > .env <<\'DPLY_ENV_EOF\'%s%sDPLY_ENV_EOF%sphp artisan key:generate --force',
            escapeshellarg($deployPath),
            "\n",
            $envBody."\n",
            "\n",
        );

        $out = $this->executor->runInlineBash(
            server: $site->server,
            name: 'scaffold-laravel:write-env',
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
            name: 'scaffold-laravel:migrate',
            inlineBash: sprintf('cd %s && php artisan migrate --force', escapeshellarg($deployPath)),
            timeoutSeconds: 120,
        );
        if ($out->getExitCode() !== 0) {
            throw new \RuntimeException('migrate failed: '.$out->getBuffer());
        }
    }

    private function stepSeedAdmin(Site $site, string $password): void
    {
        $deployPath = $this->deployPath($site);
        $email = $site->meta['scaffold']['admin_email'] ?? 'admin@example.com';
        // Run via tinker --execute so the User model + bcrypt() are
        // available without an extra seeder file landing in the repo.
        $script = sprintf(
            "\\App\\Models\\User::create(['name'=>'Admin','email'=>%s,'password'=>bcrypt(%s)]);",
            var_export($email, true),
            var_export($password, true),
        );
        $cmd = sprintf(
            'cd %s && php artisan tinker --execute=%s',
            escapeshellarg($deployPath),
            escapeshellarg($script),
        );
        $out = $this->executor->runInlineBash(
            server: $site->server,
            name: 'scaffold-laravel:seed-admin',
            inlineBash: $cmd,
            timeoutSeconds: 60,
        );
        if ($out->getExitCode() !== 0) {
            throw new \RuntimeException('seed admin failed: '.$out->getBuffer());
        }
    }

    private function stepSupervisorWorker(Site $site): void
    {
        // Placeholder — full Supervisor wiring lives in the
        // existing WorkspaceDaemons surface; v1 records that the
        // queue worker is "intended" so the WorkspaceDaemons UI
        // shows it as a pending suggestion.
        $this->setMeta($site, 'scaffold.queue_worker_suggested', true);
    }

    private function deployPath(Site $site): string
    {
        return '/home/dply/'.$site->slug.'/current';
    }

    /**
     * APP_URL for the freshly-scaffolded Laravel site. The
     * placeholder_dns pipeline step (run before write_env) guarantees
     * a primaryDomain() exists; the localhost fallback only fires for
     * sites where that step somehow short-circuited.
     *
     * v1 hard-codes http:// because cert issuance for placeholder
     * hostnames is a separate subsystem (not built yet); the operator
     * can flip APP_URL to https:// after attaching a real domain via
     * the routing tab.
     */
    private function appUrl(Site $site): string
    {
        $hostname = $site->primaryDomain()?->hostname;

        return 'http://'.(is_string($hostname) && $hostname !== '' ? $hostname : 'localhost');
    }

    private function setMeta(Site $site, string $dottedPath, mixed $value): void
    {
        $site = $site->fresh() ?? $site;
        $meta = is_array($site->meta) ? $site->meta : [];
        data_set($meta, $dottedPath, $value);
        $site->meta = $meta;
        $site->save();
    }

    private function markStep(Site $site, string $key, string $state, ?string $error = null): void
    {
        $site = $site->fresh() ?? $site;
        $meta = is_array($site->meta) ? $site->meta : [];
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
