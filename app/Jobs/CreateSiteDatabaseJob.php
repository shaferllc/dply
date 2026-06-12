<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\ConsoleAction;
use App\Models\ServerDatabase;
use App\Models\ServerDatabaseAuditEvent;
use App\Models\Site;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Servers\ServerDatabaseAuditLogger;
use App\Services\Servers\ServerDatabaseProvisioner;
use App\Services\Sites\DotEnvFileParser;
use App\Services\Sites\DotEnvFileWriter;
use App\Support\Scaffold\DatabaseConnectionEnv;
use App\Support\Servers\DatabaseWorkspaceEngines;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Provision a database for a single site over SSH, then (optionally) wire the
 * connection into the site's .env and push it to the server.
 *
 * The {@see ServerDatabase} row (with its site_id link, generated username, and
 * encrypted password) is created by the dispatching Livewire component BEFORE
 * this job runs, so a credential-share link is available immediately. This job
 * owns the slow, SSH-bound work — per the always-queue rule, none of it runs
 * inline in the request:
 *   1. CREATE DATABASE / USER on the host.
 *   2. (writeEnv) merge DB_* into the site's .env cache.
 *   3. (writeEnv && pushEnv) dispatch {@see PushSiteEnvJob} to write it live.
 *   4. (PHP sites) chain {@see EnsureSitePhpDatabaseDriverJob} so the app can
 *      actually speak the engine it was just handed.
 *
 * Progress streams into a {@see ConsoleAction} on the site so the Database tab
 * banner shows live output (same pattern as the engine-install job).
 */
class CreateSiteDatabaseJob implements ShouldQueue
{
    use Queueable;
    use WritesConsoleAction;

    public int $tries = 1;

    public int $timeout = 300;

    /** Engine families whose .env block DatabaseConnectionEnv knows how to render. */
    private const ENV_INJECTABLE_FAMILIES = ['mysql', 'mariadb', 'postgres', 'sqlite'];

    public function __construct(
        public string $serverDatabaseId,
        public string $siteId,
        public bool $writeEnv = true,
        public bool $pushEnv = false,
        public ?string $userId = null,
        public ?string $seededConsoleRunId = null,
    ) {
        $this->onQueue('dply-control');
    }

    protected function consoleSubject(): Model
    {
        return Site::query()->findOrFail($this->siteId);
    }

    protected function consoleKind(): string
    {
        return 'site_db_create';
    }

    protected function triggeringUserId(): ?string
    {
        return $this->userId;
    }

    public function handle(
        ServerDatabaseProvisioner $provisioner,
        DotEnvFileParser $parser,
        DotEnvFileWriter $writer,
        ServerDatabaseAuditLogger $audit,
    ): void {
        $db = ServerDatabase::query()->with('server')->find($this->serverDatabaseId);
        $site = Site::query()->find($this->siteId);
        if (! $db instanceof ServerDatabase || ! $site instanceof Site) {
            return;
        }

        $this->bindConsoleRunId($this->seededConsoleRunId);
        $emit = $this->beginConsoleAction();

        try {
            $emit->step('db', sprintf('CREATE %s DATABASE %s', strtoupper($db->engine), $db->name));

            $out = $provisioner->createOnServer($db);
            foreach (preg_split("/\r?\n/", (string) $out) ?: [] as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $emit($line, ConsoleAction::LEVEL_INFO, 'db');
                }
            }

            $audit->record($db->server, ServerDatabaseAuditEvent::EVENT_DATABASE_CREATED, [
                'server_database_id' => $db->id,
                'site_id' => $site->id,
                'engine' => $db->engine,
                'name' => $db->name,
                'source' => 'site_workspace',
            ]);

            if ($this->writeEnv) {
                $this->injectEnv($emit, $site, $db, $parser, $writer);
            }

            $this->maybeEnsurePhpDriver($emit, $site, $db);

            $emit->success(__('Database :name ready.', ['name' => $db->name]), 'db');
            $this->completeConsoleAction();
        } catch (\Throwable $e) {
            $message = Str::limit($e->getMessage(), 800);
            $emit->error($message, 'db');
            $this->failConsoleAction($message);

            Log::warning('CreateSiteDatabaseJob failed', [
                'server_database_id' => $this->serverDatabaseId,
                'site_id' => $this->siteId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Merge the DB_* connection vars into the site's .env cache. Engines that
     * DatabaseConnectionEnv can't render a sensible block for (mongodb,
     * clickhouse) are skipped with a note rather than writing a bogus
     * mysql-shaped block.
     */
    private function injectEnv(
        ConsoleEmitter $emit,
        Site $site,
        ServerDatabase $db,
        DotEnvFileParser $parser,
        DotEnvFileWriter $writer,
    ): void {
        if (! in_array(DatabaseWorkspaceEngines::family($db->engine), self::ENV_INJECTABLE_FAMILIES, true)) {
            $emit->info(__(':engine has no standard .env block — skipping environment write.', [
                'engine' => DatabaseWorkspaceEngines::label($db->engine),
            ]), 'db');

            return;
        }

        $block = DatabaseConnectionEnv::forEngine($db->engine, [
            'name' => $db->name,
            'username' => (string) $db->username,
            'password' => (string) $db->password,
            'host' => $db->host ?: '127.0.0.1',
            'port' => $db->defaultPort(),
            'sqlite_path' => $db->host ?: '',
        ]);

        $incoming = $parser->parse($block);
        $existing = $parser->parse((string) ($site->env_file_content ?? ''));

        $mergedVars = array_merge($existing['variables'], $incoming['variables']);
        $mergedComments = array_merge($existing['comments'], $incoming['comments']);

        $site->forceFill([
            'env_file_content' => $writer->render($mergedVars, $mergedComments),
            'env_cache_origin' => 'local-edit',
        ])->save();

        $emit->step('db', __('Wrote :count connection variable(s) to .env', [
            'count' => count($incoming['variables']),
        ]));

        if (! $this->pushEnv) {
            $emit->info(__('Push the .env from the Environment tab to apply it on the server.'), 'db');

            return;
        }

        if (! $site->server?->hostCapabilities()->supportsEnvPushToHost()) {
            $emit->info(__('This host does not support pushing a .env over SSH — wrote the cache only.'), 'db');

            return;
        }

        PushSiteEnvJob::dispatch($site->id, $this->userId);
        $emit->step('db', __('Queued .env push to the server (restart the app to apply).'));
    }

    /**
     * For PHP sites, make sure the client extension for the engine is present
     * so the app can connect. No-op for non-PHP runtimes and for engines the
     * driver job doesn't recognize (it self-filters).
     */
    private function maybeEnsurePhpDriver(
        ConsoleEmitter $emit,
        Site $site,
        ServerDatabase $db,
    ): void {
        if (strtolower((string) $site->runtime) !== 'php') {
            return;
        }

        EnsureSitePhpDatabaseDriverJob::dispatch($site->id, $db->engine);
        $emit->step('db', __('Ensuring the PHP :engine driver is installed.', [
            'engine' => DatabaseWorkspaceEngines::label($db->engine),
        ]));
    }
}
