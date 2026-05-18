<?php

namespace App\Livewire\Servers;

use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\Server;
use App\Services\Servers\ServerRemovalAdvisor;
use App\Services\SshConnection;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Basic SSH console — terminal-style surface for one-off shell commands.
 *
 * Sits alongside /run, which is the heavier "library of saved scripts"
 * page. The console keeps a rolling per-session history of
 * (prompt, output) entries so an operator can quickly run a handful of
 * inspection commands (uptime, df -h, etc.) without losing the trail
 * after the next submit.
 */
#[Layout('layouts.app')]
class WorkspaceConsole extends Component
{
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;

    /** Current prompt input. */
    public string $command = '';

    /**
     * Rolling history of executed commands, oldest first. Capped at
     * self::HISTORY_LIMIT so the component payload doesn't grow without
     * bound for chatty sessions.
     *
     * @var array<int, array{cmd: string, out: string, exit: ?int, ran_at: string, error: ?string}>
     */
    public array $history = [];

    /** Last error from a connection / exec failure (shown above the prompt). */
    public ?string $error = null;

    protected const HISTORY_LIMIT = 30;

    protected const EXEC_TIMEOUT = 60;

    /**
     * Curated set of safe, read-only "look at the box" commands surfaced
     * as quick-action buttons. Labels are intentionally short.
     *
     * @return array<int, array{label: string, cmd: string}>
     */
    public function quickActions(): array
    {
        return [
            ['label' => 'uptime', 'cmd' => 'uptime'],
            ['label' => 'disk', 'cmd' => 'df -h'],
            ['label' => 'memory', 'cmd' => 'free -h'],
            ['label' => 'who', 'cmd' => 'who'],
            ['label' => 'top processes', 'cmd' => 'ps -eo pid,user,pcpu,pmem,comm --sort=-pcpu | head -n 15'],
            ['label' => 'listening ports', 'cmd' => 'ss -tulpn 2>/dev/null | head -n 25'],
            ['label' => 'nginx status', 'cmd' => 'systemctl is-active nginx; systemctl status nginx --no-pager -n 5 2>&1 | head -n 20'],
            ['label' => 'kernel', 'cmd' => 'uname -a'],
        ];
    }

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
    }

    public function runQuickAction(int $index): void
    {
        $actions = $this->quickActions();
        if (! isset($actions[$index])) {
            return;
        }
        $this->command = $actions[$index]['cmd'];
        $this->run();
    }

    public function clearHistory(): void
    {
        $this->history = [];
        $this->error = null;
    }

    /**
     * Execute the current prompt against the server over SSH and append
     * the (command, output) pair to the rolling history.
     */
    public function run(): void
    {
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
                self::EXEC_TIMEOUT,
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

        if (count($this->history) > self::HISTORY_LIMIT) {
            $this->history = array_slice($this->history, -self::HISTORY_LIMIT);
        }

        $this->command = '';
    }

    public function render(): View
    {
        $this->server->refresh();

        return view('livewire.servers.workspace-console', [
            'quickActions' => $this->quickActions(),
            'deletionSummary' => $this->showRemoveServerModal
                ? ServerRemovalAdvisor::summary($this->server)
                : null,
        ]);
    }
}
