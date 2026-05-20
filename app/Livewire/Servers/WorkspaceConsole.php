<?php

namespace App\Livewire\Servers;

use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\RunsServerConsoleCommands;
use App\Models\Server;
use App\Services\Servers\DplyCliInstaller;
use App\Services\Servers\ServerRemovalAdvisor;
use App\Services\SshConnection;
use App\Support\Console\ConsoleArgspecs;
use App\Support\Console\ConsoleCatalog;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use App\Livewire\Concerns\RequiresFeature;
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
    use RequiresFeature;

    protected string $requiredFeature = 'workspace.console';
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;
    use RunsServerConsoleCommands;

    /** Sidebar pinned state. Persisted client-side; this is the initial render value. */
    public bool $helpOpen = false;

    /**
     * Server-discovered binary names. Populated lazily by loadProbes() after
     * the initial render via wire:init so the SSH round-trip doesn't gate
     * first paint. Capped at 2000 entries.
     *
     * @var list<string>
     */
    public array $binList = [];

    /**
     * Recent shell history lines (dedup'd, most-recent-first, capped at 100).
     * Populated by loadProbes(). Held in component state only — never
     * persisted to dply's DB.
     *
     * @var list<string>
     */
    public array $historyList = [];

    public bool $probesLoaded = false;

    public ?string $probeError = null;

    /**
     * Composite CLI install state computed during loadProbes:
     *   'unknown' — probes haven't run yet
     *   'missing' — no binary on the box
     *   'partial' — binary present but jq missing OR state file missing/invalid
     *   'ok'      — binary + jq + readable state file with matching schema
     */
    public string $cliState = 'unknown';

    /** Installed CLI version (e.g. "0.1.0") when the binary is present. */
    public ?string $cliVersion = null;

    /** Sub-flags so the UI can spell out which pieces are missing. */
    public bool $cliBinaryOk = false;

    public bool $cliJqOk = false;

    public bool $cliStateFileOk = false;

    /** Surface a "busy" state on the install/repair button. */
    public bool $cliInstalling = false;

    /** Last install attempt failure, shown in the install banner. */
    public ?string $cliInstallError = null;

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

    public function toggleHelp(): void
    {
        $this->helpOpen = ! $this->helpOpen;
    }

    /**
     * Background SSH probes for autocomplete sources. Runs once per page mount
     * via wire:init. Results live in component state for the lifetime of the
     * page and are NOT persisted — bash history can contain pasted tokens, so
     * keeping it request-scoped bounds blast radius.
     *
     * Two probes are bundled into a single SSH exec to avoid a second
     * connection round-trip — that's 200ms+ on cold servers.
     */
    public function loadProbes(): void
    {
        $this->authorize('view', $this->server);
        if ($this->probesLoaded) {
            return;
        }
        $this->probesLoaded = true;

        $script = <<<'SH'
bash -lc 'compgen -c 2>/dev/null | LC_ALL=C sort -u | grep -v "^_" | head -n 2000'
echo "===DPLY-PROBE-SEPARATOR==="
{ tail -n 200 ~/.bash_history 2>/dev/null; tail -n 200 ~/.zsh_history 2>/dev/null | sed -E 's/^: [0-9]+:[0-9]+;//'; } | awk 'NF' | tail -n 200
SH;

        try {
            $ssh = new SshConnection($this->server);

            // Composite CLI probe — all three pieces in one exec so we know
            // exactly which parts of a prior install survived. Prefixed lines
            // make parsing tolerant of motd/stderr noise on the SSH session.
            $cliProbeScript = <<<'SH'
echo "BIN:$(test -x /usr/local/bin/dply && /usr/local/bin/dply version 2>/dev/null || echo missing)"
echo "JQ:$(command -v jq >/dev/null 2>&1 && echo present || echo missing)"
echo "STATE:$(test -r /etc/dply/state.json && (jq -r '.schema_version // empty' /etc/dply/state.json 2>/dev/null || echo unreadable) || echo missing)"
SH;
            $cliProbeOut = $ssh->exec($cliProbeScript, 10);
            foreach (explode("\n", $cliProbeOut) as $line) {
                $line = trim($line);
                if (str_starts_with($line, 'BIN:')) {
                    $val = substr($line, 4);
                    if ($val !== 'missing' && $val !== '') {
                        $this->cliBinaryOk = true;
                        if (preg_match('/^dply\s+(\S+)/', $val, $m) === 1) {
                            $this->cliVersion = $m[1];
                        }
                    }
                } elseif (str_starts_with($line, 'JQ:')) {
                    $this->cliJqOk = substr($line, 3) === 'present';
                } elseif (str_starts_with($line, 'STATE:')) {
                    $val = substr($line, 6);
                    // We accept any numeric schema version for now; mismatch
                    // detection arrives in Phase 3 once we have multiple
                    // schemas in the wild.
                    $this->cliStateFileOk = $val !== '' && $val !== 'missing' && $val !== 'unreadable';
                }
            }

            if (! $this->cliBinaryOk) {
                $this->cliState = 'missing';
            } elseif (! $this->cliJqOk || ! $this->cliStateFileOk) {
                $this->cliState = 'partial';
            } else {
                $this->cliState = 'ok';
            }

            $out = $ssh->exec($script, 15);
            [$bins, $hist] = array_pad(explode("===DPLY-PROBE-SEPARATOR===\n", $out, 2), 2, '');

            $binLines = array_values(array_filter(
                array_map('trim', explode("\n", $bins)),
                static fn (string $s) => $s !== '' && ! str_starts_with($s, '_'),
            ));
            $this->binList = $binLines;

            $histLines = array_values(array_filter(
                array_map('trim', explode("\n", $hist)),
                static fn (string $s) => $s !== '',
            ));
            // Most-recent-first, dedup preserving order.
            $seen = [];
            $deduped = [];
            foreach (array_reverse($histLines) as $line) {
                if (isset($seen[$line])) {
                    continue;
                }
                $seen[$line] = true;
                $deduped[] = $line;
                if (count($deduped) >= 100) {
                    break;
                }
            }
            $this->historyList = $deduped;
        } catch (\Throwable $e) {
            $this->probeError = $e->getMessage();
        }
    }

    /**
     * Install (or upgrade) the bash `dply` CLI on the server. Wraps the
     * DplyCliInstaller service. Deployer-blocked because it writes to
     * /usr/local/bin and runs apt-get.
     */
    public function installCli(DplyCliInstaller $installer): void
    {
        $this->authorize('update', $this->server);
        if (auth()->user()?->currentOrganization()?->userIsDeployer(auth()->user())) {
            $this->cliInstallError = __('Deployers cannot install the dply CLI.');

            return;
        }

        $this->cliInstalling = true;
        $this->cliInstallError = null;

        try {
            $version = $installer->install($this->server);
            $this->cliVersion = $version;
            $this->cliBinaryOk = true;
            $this->cliJqOk = true;
            $this->cliStateFileOk = true;
            $this->cliState = 'ok';
            $this->toastSuccess(__('dply CLI installed (v:version).', ['version' => $version]));
        } catch (\Throwable $e) {
            $this->cliInstallError = $e->getMessage();
            // Re-probe so the banner reflects whatever survived a partial install
            // attempt — without this the user sees a stale state until reload.
            $this->probesLoaded = false;
            $this->loadProbes();
        } finally {
            $this->cliInstalling = false;
        }
    }

    public function render(): View
    {
        $this->server->refresh();

        $sections = ConsoleCatalog::for($this->server);

        // Flatten catalog commands for the autocomplete source. The sidebar still
        // gets the structured sections; this is the same data, one level flatter.
        $catalogCommands = [];
        foreach ($sections as $section) {
            foreach ($section['entries'] as $entry) {
                $catalogCommands[] = $entry['command'];
            }
        }

        return view('livewire.servers.workspace-console', [
            'quickActions' => $this->quickActions(),
            'catalogSections' => $sections,
            'catalogCommands' => array_values(array_unique($catalogCommands)),
            'argspecs' => ConsoleArgspecs::for($this->server),
            'deletionSummary' => $this->showRemoveServerModal
                ? ServerRemovalAdvisor::summary($this->server)
                : null,
        ]);
    }
}
