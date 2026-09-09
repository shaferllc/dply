<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ConsoleAction;
use App\Models\Server;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\SshConnectionFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Runs one ad-hoc operator command on a server, off the request.
 *
 * The API used to open SSH inside the HTTP request, so anything slower than the
 * web request timeout was truncated with no record of what happened — and the
 * house rule forbids SSH in the request path regardless. The work now happens
 * here and the caller polls the ConsoleAction.
 *
 * Semantics are deliberately identical to the old inline call: a plain
 * `exec()`, not the `set -euo pipefail` wrapper the task runner applies, so a
 * command that worked before behaves the same now. A non-zero exit is recorded,
 * not raised: `grep` finding nothing is data, not a failure of the run.
 */
class RunServerCommandJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public function __construct(
        public string $consoleActionId,
        public string $serverId,
        public string $command,
    ) {}

    public function handle(SshConnectionFactory $ssh): void
    {
        $server = Server::query()->find($this->serverId);
        $action = ConsoleAction::query()->find($this->consoleActionId);

        if ($server === null || $action === null) {
            return;
        }

        DB::table('console_actions')->where('id', $this->consoleActionId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);

        $emit = new ConsoleEmitter($this->consoleActionId);
        $emit->step('command', $this->command);

        try {
            [$output, $exitCode] = $ssh->forServer($server)
                ->execWithCallbackAndExit($this->command, static function (): void {}, $this->timeout - 60);

            $buffer = rtrim((string) $output);
            if ($buffer === '') {
                $emit->info('(no output)', 'command');
            } else {
                // Capped on the way in so one `journalctl` cannot grow the row
                // without bound; the tail is the useful end of a long run.
                foreach (preg_split('/\r?\n/', mb_substr($buffer, -16_000)) ?: [] as $line) {
                    if ($line !== '') {
                        $emit($line, ConsoleAction::LEVEL_INFO, 'command');
                    }
                }
            }

            $exit = $exitCode ?? 0;
            if ($exit === 0) {
                $emit->success('Command completed.', 'command');
            } else {
                $emit->warn("Command exited {$exit}.", 'command');
            }

            DB::table('console_actions')->where('id', $this->consoleActionId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                // The exit code lives in `error` so a poll can report it without
                // a schema change; a zero exit records nothing.
                'error' => $exit === 0 ? null : "exit {$exit}",
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $emit->error('The command could not be run on this server.', 'command');

            DB::table('console_actions')->where('id', $this->consoleActionId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
        }
    }
}
