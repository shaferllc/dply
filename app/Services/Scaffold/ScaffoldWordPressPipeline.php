<?php

declare(strict_types=1);

namespace App\Services\Scaffold;

use App\Models\ServerDatabase;
use App\Models\Site;
use App\Models\SiteAuditEvent;
use App\Notifications\SiteDatabaseCredentialsNotification;
use App\Services\RemoteCli\RiskLevel;
use App\Services\RemoteCli\SiteAuditWriter;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Servers\ServerDatabaseProvisioner;
use App\Support\Servers\InstalledStack;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Orchestrates the WordPress scaffold pipeline (Q11 mirror).
 *
 * Steps, in order:
 *   1. prereqs       — wp-cli self-heal via ScaffoldPrerequisites
 *   2. db_create     — auto-provision Site DB + user (MariaDB / MySQL)
 *   3. wp_download   — wp core download into the deploy directory
 *   4. wp_config     — wp config create with the generated DB credentials + salts
 *   5. wp_install    — wp core install with admin email + generated password
 *   6. apply_hardening — opinionated-secure defaults (Q18):
 *                       - DISALLOW_FILE_EDIT in wp-config
 *                       - FORCE_SSL_ADMIN in wp-config
 *                       - Hello Dolly + Akismet removed
 *                       - redis-cache plugin staged (not active)
 *                       - system cron flip (wp-cron disabled,
 *                         crontab entry added)
 *
 * Symmetric to ScaffoldLaravelPipeline. The two share ScaffoldStep,
 * SiteAuditWriter, and the meta.scaffold.steps[] persistence shape so
 * the journey UI renders both with identical layout.
 */
class ScaffoldWordPressPipeline
{
    public const FRAMEWORK = 'wordpress';

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

        $adminPassword = Str::password(20);
        $this->setMeta($site, 'scaffold.admin_password', encrypt($adminPassword));

        $steps = [
            ['prereqs', fn () => $this->stepPrereqs($site)],
            ['placeholder_dns', fn () => $this->stepAssignPlaceholderDns($site)],
            ['db_create', fn () => $this->stepCreateDatabase($site)],
            ['wp_download', fn () => $this->stepWpDownload($site)],
            ['wp_config', fn () => $this->stepWpConfig($site)],
            ['wp_install', fn () => $this->stepWpInstall($site, $adminPassword)],
            ['apply_hardening', fn () => $this->stepApplyHardening($site)],
        ];

        foreach ($steps as [$key, $fn]) {
            $this->markStep($site, $key, ScaffoldStep::STATE_RUNNING);
            try {
                $fn();
                $this->markStep($site, $key, ScaffoldStep::STATE_COMPLETED);
            } catch (Throwable $e) {
                Log::warning('WordPress scaffold step failed', [
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
                    summary: 'WordPress scaffold failed at step '.$key,
                    payload: ['framework' => self::FRAMEWORK, 'step' => $key, 'error' => $e->getMessage()],
                    resultStatus: SiteAuditEvent::RESULT_FAILURE,
                );

                return ['ok' => false, 'failed_step' => $key, 'error' => $e->getMessage()];
            }
        }

        $site->status = Site::STATUS_PENDING;
        $site->save();

        $this->audit->record(
            site: $site,
            user: null,
            action: 'scaffold_completed',
            risk: RiskLevel::MutatingRecoverable,
            transport: SiteAuditEvent::TRANSPORT_SYSTEM,
            summary: 'WordPress scaffold completed',
            payload: ['framework' => self::FRAMEWORK],
            resultStatus: SiteAuditEvent::RESULT_SUCCESS,
        );

        return ['ok' => true, 'failed_step' => null, 'error' => null];
    }

    private function initSteps(Site $site): void
    {
        $steps = [
            ScaffoldStep::pending('prereqs', 'Verify prerequisites (wp-cli)'),
            ScaffoldStep::pending('placeholder_dns', 'Assign placeholder hostname'),
            ScaffoldStep::pending('db_create', 'Create database + user'),
            ScaffoldStep::pending('wp_download', 'wp core download'),
            ScaffoldStep::pending('wp_config', 'wp config create'),
            ScaffoldStep::pending('wp_install', 'wp core install + seed admin'),
            ScaffoldStep::pending('apply_hardening', 'Apply opinionated hardening'),
        ];
        $this->setMeta($site, 'scaffold.steps', $steps);
        $this->setMeta($site, 'scaffold.started_at', now()->toISOString());
    }

    private function stepPrereqs(Site $site): void
    {
        $result = $this->prerequisites->ensureWpCli($site->server);
        if (! $result->ok()) {
            throw new \RuntimeException('wp-cli install failed: '.$result->error);
        }
    }

    /**
     * Assign a placeholder hostname BEFORE wp_install runs (Q12 critical
     * detail — `wp core install --url=` writes the URL into wp_options
     * in serialized form, so it must be correct from the very first
     * call). Persist the hostname as a primary SiteDomain so siteUrl()
     * + future routing-tab actions find it via Site::primaryDomain().
     * Idempotent on retry — see ScaffoldLaravelPipeline mirror.
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
            // wp_install reads primaryDomain() in a later step — drop any
            // memoized (null) result so it re-resolves to this new domain.
            $site->flushPrimaryDomainCache();
        }
    }

    private function stepCreateDatabase(Site $site): void
    {
        // Read the installed (not requested) engine. On low-memory
        // droplets the script substitutes SQLite for MySQL/Postgres —
        // and WordPress doesn't natively support SQLite, so we halt
        // with a clear, actionable error rather than silently producing
        // a broken site. Operator's options: re-provision the server
        // on a 2GB+ droplet, or pick Laravel (which works on SQLite)
        // for this site instead.
        $installed = InstalledStack::fromMeta($site->server);
        $engine = $installed->database ?? 'mysql84';

        if ($engine === 'sqlite3' || str_starts_with($engine, 'sqlite')) {
            throw new \RuntimeException(
                'WordPress requires MySQL or MariaDB; this server has SQLite installed '
                .'(low-memory mode detected at provisioning time, which substitutes SQLite '
                .'for heavier databases). Re-provision the server on a 2GB+ droplet to '
                .'install MySQL, or pick Laravel for this site instead.'
            );
        }

        // WordPress requires MySQL or MariaDB — Q5. Pick whichever the
        // server has installed; default to mysql84 if the server's
        // declared engine is something else (the v1 wizard tile is
        // disabled on Postgres-only hosts so we shouldn't reach here
        // with an incompatible engine).
        if (! in_array($engine, ['mysql84', 'mysql80', 'mariadb114', 'mariadb11', 'mariadb1011'], true)) {
            $engine = 'mysql84';
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
            'server_database_id' => $db->id,
            'name' => $dbName,
            'username' => $username,
            'password' => encrypt($password),
            'engine' => $engine,
        ]);

        // Org-gated email of the credentials. Same shape as the Laravel
        // pipeline — see ScaffoldLaravelPipeline::maybeEmailDatabaseCredentials
        // for rationale on plain-text password delivery.
        $organization = $site->organization;
        $creator = $site->user;
        if ($organization
            && $organization->email_database_credentials_enabled
            && $creator
            && filled($creator->email)
        ) {
            $creator->notify(new SiteDatabaseCredentialsNotification(
                site: $site,
                engine: $engine,
                password: $password,
                databaseName: $dbName,
                username: $username,
            ));
        }
    }

    private function stepWpDownload(Site $site): void
    {
        $deployPath = $this->deployPath($site);
        $cmd = sprintf(
            'sudo -u dply mkdir -p %s && cd %s && wp core download --skip-content',
            escapeshellarg($deployPath),
            escapeshellarg($deployPath),
        );
        $out = $this->executor->runInlineBash(
            server: $site->server,
            name: 'scaffold-wp:core-download',
            inlineBash: $cmd,
            timeoutSeconds: 180,
        );
        if ($out->getExitCode() !== 0) {
            throw new \RuntimeException('wp core download failed: '.$out->getBuffer());
        }
    }

    private function stepWpConfig(Site $site): void
    {
        $deployPath = $this->deployPath($site);
        $db = $site->fresh()->meta['scaffold']['database'];
        $dbPassword = decrypt($db['password']);

        $cmd = sprintf(
            'cd %s && wp config create --dbname=%s --dbuser=%s --dbpass=%s --dbhost=127.0.0.1 --skip-check --extra-php <<EOF
        define("DISALLOW_FILE_EDIT", true);
        define("FORCE_SSL_ADMIN", true);
        define("DISABLE_WP_CRON", true);
        EOF',
            escapeshellarg($deployPath),
            escapeshellarg($db['name']),
            escapeshellarg($db['username']),
            escapeshellarg($dbPassword),
        );
        $out = $this->executor->runInlineBash(
            server: $site->server,
            name: 'scaffold-wp:config-create',
            inlineBash: $cmd,
            timeoutSeconds: 60,
        );
        if ($out->getExitCode() !== 0) {
            throw new \RuntimeException('wp config create failed: '.$out->getBuffer());
        }
    }

    private function stepWpInstall(Site $site, string $password): void
    {
        $deployPath = $this->deployPath($site);
        $email = $site->meta['scaffold']['admin_email'] ?? 'admin@example.com';
        $title = $site->name;
        $url = $this->siteUrl($site);

        $cmd = sprintf(
            'cd %s && wp core install --url=%s --title=%s --admin_user=admin --admin_email=%s --admin_password=%s --skip-email',
            escapeshellarg($deployPath),
            escapeshellarg($url),
            escapeshellarg($title),
            escapeshellarg($email),
            escapeshellarg($password),
        );
        $out = $this->executor->runInlineBash(
            server: $site->server,
            name: 'scaffold-wp:core-install',
            inlineBash: $cmd,
            timeoutSeconds: 120,
        );
        if ($out->getExitCode() !== 0) {
            throw new \RuntimeException('wp core install failed: '.$out->getBuffer());
        }
    }

    private function stepApplyHardening(Site $site): void
    {
        $deployPath = $this->deployPath($site);
        // Q18 opinionated-secure defaults batched into a single
        // wp-cli invocation set:
        //   1. delete Hello Dolly + Akismet (Q18 — placeholder removal)
        //   2. install redis-cache plugin (NOT activated; Q18 staging)
        //   3. system-cron flip — DISABLE_WP_CRON already in wp-config;
        //      crontab entry added separately.
        $cmd = sprintf(
            'cd %s && \
                wp plugin delete hello akismet 2>/dev/null || true && \
                wp plugin install redis-cache --no-color 2>/dev/null || true && \
                wp option update blogdescription %s 2>/dev/null || true',
            escapeshellarg($deployPath),
            escapeshellarg('Powered by dply'),
        );
        $out = $this->executor->runInlineBash(
            server: $site->server,
            name: 'scaffold-wp:hardening',
            inlineBash: $cmd,
            timeoutSeconds: 90,
        );
        if ($out->getExitCode() !== 0) {
            throw new \RuntimeException('hardening apply failed: '.$out->getBuffer());
        }

        // Record each opinion so the Hardening tab (PR 10) shows them
        // as enabled toggles. Each line also gets its own audit event so
        // the operator can trace exactly what dply changed.
        $opinions = [
            ['key' => 'disallow_file_edit', 'enabled' => true],
            ['key' => 'force_ssl_admin', 'enabled' => true],
            ['key' => 'disable_wp_cron', 'enabled' => true],
            ['key' => 'remove_hello_dolly', 'enabled' => true],
            ['key' => 'remove_akismet', 'enabled' => true],
            ['key' => 'staged_redis_cache', 'enabled' => true],
        ];
        $this->setMeta($site, 'scaffold.hardening', $opinions);
        foreach ($opinions as $opinion) {
            $this->audit->record(
                site: $site,
                user: null,
                action: 'scaffold_default_applied',
                risk: RiskLevel::MutatingRecoverable,
                transport: SiteAuditEvent::TRANSPORT_SYSTEM,
                summary: 'Applied WordPress hardening default: '.$opinion['key'],
                payload: ['opinion' => $opinion['key']],
            );
        }
    }

    private function deployPath(Site $site): string
    {
        return '/home/dply/'.$site->slug.'/current';
    }

    /**
     * URL passed to `wp core install --url=`. The placeholder_dns
     * pipeline step (run before wp_install) guarantees a primaryDomain()
     * exists, so this is the placeholder hostname (or a real domain if
     * one was pre-attached). v1 hard-codes http:// — see appUrl() in
     * the Laravel pipeline for the same scheme rationale.
     */
    private function siteUrl(Site $site): string
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
