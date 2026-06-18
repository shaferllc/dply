<?php

declare(strict_types=1);

namespace App\Modules\RemoteCli\Services;

use App\Modules\RemoteCli\Jobs\RunRemoteCliInBackgroundJob;
use App\Models\RemoteCliRun;
use App\Models\Site;
use App\Models\SiteAuditEvent;
use App\Models\User;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Shared base for {@see WpCli} and {@see Artisan}.
 *
 * Each invocation goes through these steps:
 *
 *   1. Risk classification — {@see classifyRisk()} consults the
 *      subclass's static lookup table; unknown commands fall through
 *      to {@see RiskLevel::Destructive} as the failsafe (Q17).
 *   2. Permission gate — {@see RemoteCliPermissions} consults the
 *      site's org RBAC. Read + MutatingRecoverable allowed for any
 *      member; Destructive requires admin/owner. System-triggered
 *      runs ($queuedBy === null) bypass the gate.
 *   3. Sync vs async routing — commands listed by {@see instantCommands()}
 *      run synchronously over {@see ExecuteRemoteTaskOnServer} with a
 *      tight timeout (5s); everything else dispatches a queued job.
 *   4. Persistence + audit — every invocation produces exactly one
 *      {@see RemoteCliRun} row plus, for non-Read commands, a
 *      {@see SiteAuditEvent} entry on settled state.
 */
abstract class RemoteCli
{
    /** Sync timeout — falls back to async on overrun. Q15. */
    public const SYNC_TIMEOUT_SECONDS = 5;

    public function __construct(
        protected readonly ExecuteRemoteTaskOnServer $executor,
        protected readonly RemoteCliPermissions $permissions,
        protected readonly SiteAuditWriter $auditWriter,
    ) {}

    abstract public function kind(): Kind;

    /**
     * Map a command (e.g. 'plugin install', 'migrate:rollback') to its
     * risk level. Subclass overrides consult their per-tool table.
     * Unknown commands MUST return {@see RiskLevel::Destructive}.
     */
    abstract public function classifyRisk(string $command): RiskLevel;

    /**
     * List of commands that complete fast enough to be safely run
     * synchronously inside an HTTP request. Match by exact command
     * key (the first wp/artisan word + subcommand, e.g. 'plugin list',
     * 'migrate:status'). Anything not on this list runs async.
     *
     * @return list<string>
     */
    abstract protected function instantCommands(): array;

    /**
     * Produce the full shell invocation that runs the command on the
     * server inside the site's working directory. Subclasses build
     * the right binary + path prefix.
     *
     * @param  array<string, mixed> $args
     */
    abstract protected function buildShellCommand(Site $site, string $command, array $args): string;

    /**
     * Public entry-point for the async worker, which doesn't have
     * direct access to the protected builder.
     *
     * @param  array<string, mixed> $args
     */
    public function buildShellForRun(Site $site, string $command, array $args): string
    {
        return $this->buildShellCommand($site, $command, $args);
    }

    /**
     * Run a command against the given site.
     *
     * @param  array<string, mixed> $args
     *
     * @throws RemoteCliPermissionDeniedException When $queuedBy lacks
     *                                            the role for the command's risk level.
     */
    public function run(
        Site $site,
        string $command,
        array $args = [],
        ?User $queuedBy = null,
    ): RemoteCliResult {
        $command = trim($command);
        if ($command === '') {
            throw new \InvalidArgumentException('RemoteCli::run() requires a non-empty command.');
        }

        $risk = $this->classifyRisk($command);

        // Permission gate first — fail before persisting anything.
        $this->permissions->ensureCan($queuedBy, $site, $risk, $command);

        $mode = $this->isInstant($command) ? RemoteCliRun::MODE_SYNC : RemoteCliRun::MODE_ASYNC;

        $run = new RemoteCliRun([
            'site_id' => $site->getKey(),
            'kind' => $this->kind(),
            'command' => $command,
            'args' => $args,
            'risk' => $risk,
            'mode' => $mode,
            'status' => $mode === RemoteCliRun::MODE_SYNC
                ? RemoteCliRun::STATUS_RUNNING
                : RemoteCliRun::STATUS_QUEUED,
            'queued_by_user_id' => $queuedBy?->getKey(),
            'started_at' => $mode === RemoteCliRun::MODE_SYNC ? now() : null,
        ]);
        $run->save();

        if ($mode === RemoteCliRun::MODE_SYNC) {
            return $this->executeSync($site, $run, $args, $queuedBy);
        }

        // Async — dispatch the worker. The worker writes the audit row
        // on completion; we record nothing here besides the queued status.
        RunRemoteCliInBackgroundJob::dispatch($run->id);

        return new RemoteCliResult($run);
    }

    /**
     * Execute synchronously over the existing TaskRunner SSH layer,
     * splitting stdout / stderr via the per-chunk callback.
     *
     * On exit code != 0 OR timeout the run is marked 'failed'. PR 3+
     * may add an "auto-fallback to async on timeout" path per Q15;
     * v2 of the gate. v1 just records the failure.
     * @param  array<string, mixed> $args
     */
    protected function executeSync(Site $site, RemoteCliRun $run, array $args, ?User $queuedBy): RemoteCliResult
    {
        $shellCommand = $this->buildShellCommand($site, $run->command, $args);
        $stdout = '';
        $stderr = '';

        try {
            $output = $this->executor->runInlineBashWithOutputCallback(
                server: $site->server,
                name: sprintf('remote-cli:%s:%s', $run->kind->value, $run->command),
                inlineBash: $shellCommand,
                onOutput: function (string $type, string $chunk) use (&$stdout, &$stderr): void {
                    if ($type === 'err') {
                        $stderr .= $chunk;
                    } else {
                        $stdout .= $chunk;
                    }
                },
                timeoutSeconds: self::SYNC_TIMEOUT_SECONDS,
            );
        } catch (Throwable $e) {
            Log::warning('RemoteCli sync execution failed', [
                'site_id' => $site->getKey(),
                'kind' => $run->kind->value,
                'command' => $run->command,
                'error' => $e->getMessage(),
            ]);

            $run->fill([
                'status' => RemoteCliRun::STATUS_FAILED,
                'stderr' => trim(($stderr !== '' ? $stderr."\n" : '').$e->getMessage()),
                'stdout' => $stdout !== '' ? $stdout : null,
                'finished_at' => now(),
            ])->save();

            $this->writeAuditOnSettle($site, $queuedBy, $run);

            return new RemoteCliResult($run);
        }

        $exitCode = $output->getExitCode();
        $isTimeout = $output->isTimeout();

        $run->fill([
            'status' => $isTimeout || $exitCode !== 0
                ? RemoteCliRun::STATUS_FAILED
                : RemoteCliRun::STATUS_COMPLETED,
            'exit_code' => $exitCode,
            'stdout' => $stdout !== '' ? $stdout : null,
            'stderr' => $stderr !== '' ? $stderr : null,
            'finished_at' => now(),
        ])->save();

        $this->writeAuditOnSettle($site, $queuedBy, $run);

        return new RemoteCliResult($run);
    }

    /**
     * Audit Read commands are filtered by SiteAuditWriter itself, so
     * we always call it here and let the writer drop reads.
     */
    protected function writeAuditOnSettle(Site $site, ?User $user, RemoteCliRun $run): void
    {
        $this->auditWriter->record(
            site: $site,
            user: $user,
            action: $run->kind === Kind::Wp ? 'wp_cli_run' : 'artisan_run',
            risk: $run->risk,
            transport: SiteAuditEvent::TRANSPORT_WEB,
            summary: sprintf('%s %s', $run->kind->label(), $run->command),
            payload: [
                'command' => $run->command,
                'args' => $run->args,
                'exit_code' => $run->exit_code,
            ],
            resultStatus: $run->status === RemoteCliRun::STATUS_COMPLETED
                ? SiteAuditEvent::RESULT_SUCCESS
                : SiteAuditEvent::RESULT_FAILURE,
        );
    }

    public function isInstant(string $command): bool
    {
        $command = trim($command);

        foreach ($this->instantCommands() as $allowed) {
            if ($command === $allowed || str_starts_with($command, $allowed.' ')) {
                return true;
            }
        }

        return false;
    }
}
