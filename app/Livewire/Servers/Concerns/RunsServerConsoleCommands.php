<?php

namespace App\Livewire\Servers\Concerns;

use App\Models\Server;
use App\Services\SshConnectionFactory;
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

    /** Whether a command is currently running. Prevents concurrent execution. */
    public bool $consoleRunning = false;

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

        // Prevent concurrent command execution
        if ($this->consoleRunning) {
            $this->error = __('A command is already running. Wait for it to complete.');

            return;
        }

        $this->validate([
            'command' => 'required|string|max:2000',
        ]);

        // Check server is still ready before attempting SSH
        $this->server->refresh();
        if ($this->server->status !== Server::STATUS_READY) {
            $this->error = __('Server is not ready (status: :status). Cannot execute commands.', [
                'status' => $this->server->status,
            ]);

            return;
        }

        if (empty($this->server->ssh_private_key)) {
            $this->error = __('Server SSH key is not configured. Cannot connect.');

            return;
        }

        $this->error = null;
        $this->consoleRunning = true;
        $cmd = trim($this->command);
        $startedAt = microtime(true);

        try {
            $ssh = app(SshConnectionFactory::class)->forServer($this->server);
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

            $this->logConsoleAudit($cmd, $exit, null, $startedAt);
        } catch (\Throwable $e) {
            $errorMessage = $this->classifyError($e);

            $this->history[] = [
                'cmd' => $cmd,
                'out' => '',
                'exit' => null,
                'ran_at' => now()->toIso8601String(),
                'error' => $errorMessage,
            ];

            $this->logConsoleAudit($cmd, null, $errorMessage, $startedAt);
        } finally {
            $this->consoleRunning = false;
        }

        if (count($this->history) > self::CONSOLE_HISTORY_LIMIT) {
            $this->history = array_slice($this->history, -self::CONSOLE_HISTORY_LIMIT);
        }

        $this->command = '';
    }

    /**
     * Classify SSH/execution errors into user-friendly messages.
     */
    protected function classifyError(\Throwable $e): string
    {
        $message = $e->getMessage();

        // SSH connection errors
        if (str_contains($message, 'Connection refused') || str_contains($message, 'Connection timed out')) {
            return __('SSH connection failed: Server may be offline or unreachable.');
        }

        if (str_contains($message, 'Authentication failed') || str_contains($message, 'Invalid key')) {
            return __('SSH authentication failed: Check server SSH key configuration.');
        }

        if (str_contains($message, 'Host key verification failed')) {
            return __('SSH host key verification failed. Server identity may have changed.');
        }

        // Timeout errors
        if (str_contains($message, 'timeout') || str_contains($message, 'timed out')) {
            return __('Command timed out after :seconds seconds. Try a simpler command or use the Run page for long-running operations.', [
                'seconds' => self::CONSOLE_EXEC_TIMEOUT,
            ]);
        }

        // Permission errors
        if (str_contains($message, 'Permission denied')) {
            return __('Permission denied: The SSH user does not have permission to run this command.');
        }

        // Generic fallback
        return Str::limit($message, 200);
    }

    /**
     * Persist a one-line audit record per shell command. Command text is
     * stored verbatim (the operator typed it knowing it was logged); output
     * is intentionally NOT stored — it can contain secrets pasted into a
     * shell and the operator already sees it in the rolling history above.
     */
    protected function logConsoleAudit(string $command, ?int $exit, ?string $error, float $startedAt): void
    {
        $organization = $this->server->organization;
        if ($organization === null) {
            return;
        }

        $duration = (int) round((microtime(true) - $startedAt) * 1000);
        $status = $error !== null ? 'failed' : ($exit === 0 ? 'success' : 'nonzero_exit');

        audit_log(
            $organization,
            auth()->user(),
            'server.console.command_run',
            $this->server,
            null,
            [
                'command' => Str::limit($command, 1000),
                'exit_code' => $exit,
                'status' => $status,
                'duration_ms' => $duration,
                'error' => $error !== null ? Str::limit($error, 500) : null,
                'surface' => static::class,
            ],
        );
    }

    public function insertCommand(string $command): void
    {
        $this->command = $command;
    }
}
