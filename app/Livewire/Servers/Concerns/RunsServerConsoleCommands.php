<?php

namespace App\Livewire\Servers\Concerns;

use App\Services\SshConnection;
use Illuminate\Support\Str;

/**
 * Shared SSH "type a command, see output" engine.
 *
 * Owned state: the prompt buffer ({@see $command}), a rolling per-session
 * history of (cmd, output, exit) entries, and the last connection error.
 *
 * Caps:
 *   - History size  : self::CONSOLE_HISTORY_LIMIT entries
 *   - Output / entry: 16 KB, longer is truncated with an inline marker
 *   - Exec timeout  : self::CONSOLE_EXEC_TIMEOUT seconds
 *
 * Consumers (WorkspaceConsole, ConsoleDrawer) must expose `$server` and an
 * `authorize('view', $server)`-capable Livewire component.
 *
 * Deployer-role guard mirrors the policy on the Run page — deployers can
 * trigger named recipes but not arbitrary shell.
 */
trait RunsServerConsoleCommands
{
    public string $command = '';

    /**
     * @var array<int, array{cmd: string, out: string, exit: ?int, ran_at: string, error: ?string}>
     */
    public array $history = [];

    public ?string $error = null;

    protected const CONSOLE_HISTORY_LIMIT = 30;

    protected const CONSOLE_EXEC_TIMEOUT = 60;

    public function clearHistory(): void
    {
        $this->history = [];
        $this->error = null;
    }

    /**
     * Execute the current prompt against $server over SSH and append the
     * (command, output) pair to history. No-op on empty input.
     */
    public function run(): void
    {
        // Drawer consumers can have a nullable server; bail cleanly so a
        // submit without an active server doesn't leak a 403/null-deref.
        if (! property_exists($this, 'server') || $this->server === null) {
            $this->error = __('Pick a server first.');

            return;
        }
        $this->authorize('view', $this->server);

        if (auth()->user()?->currentOrganization()?->userIsDeployer(auth()->user())) {
            $this->error = __('Deployers cannot run shell commands on servers.');

            return;
        }

        $this->validate([
            'command' => 'required|string|max:2000',
        ]);

        $this->error = null;
        $cmd = trim($this->command);

        try {
            $ssh = new SshConnection($this->server);
            [$out, $exit] = $ssh->execWithCallbackAndExit(
                $cmd.' 2>&1',
                static fn (string $chunk) => null,
                self::CONSOLE_EXEC_TIMEOUT,
            );

            $this->history[] = [
                'cmd' => $cmd,
                'out' => Str::limit($out, 16000, "\n… (output truncated)"),
                'exit' => $exit,
                'ran_at' => now()->toIso8601String(),
                'error' => null,
            ];
        } catch (\Throwable $e) {
            $this->history[] = [
                'cmd' => $cmd,
                'out' => '',
                'exit' => null,
                'ran_at' => now()->toIso8601String(),
                'error' => $e->getMessage(),
            ];
        }

        if (count($this->history) > self::CONSOLE_HISTORY_LIMIT) {
            $this->history = array_slice($this->history, -self::CONSOLE_HISTORY_LIMIT);
        }

        $this->command = '';
    }

    public function insertCommand(string $command): void
    {
        $this->command = $command;
    }
}
