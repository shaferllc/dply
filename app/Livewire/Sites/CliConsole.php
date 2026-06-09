<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Models\ApiToken;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Process;
use Livewire\Component;

/**
 * In-browser dply CLI runner — runs the local CLI binary against this app's
 * API using a short-lived session token, capturing output in terminal style.
 *
 * Intended for internal dev/testing: no external CLI install needed.
 */
class CliConsole extends Component
{
    public Site $site;

    public Server $server;

    public string $input = '';

    /** @var list<array{cmd: string, out: string, exit: int|null, error: string|null}> */
    public array $history = [];

    /** Abilities the session token receives — enough for all site read + deploy. */
    private const TOKEN_ABILITIES = [
        'sites.read',
        'sites.deploy',
        'auth_users.read',
        'certificates.read',
        'database.read',
        'system_users.read',
    ];

    public function mount(Site $site, Server $server): void
    {
        $this->site = $site;
        $this->server = $server;
        $this->input = 'sites:show '.$site->slug;
    }

    public function run(): void
    {
        $raw = trim($this->input);
        if ($raw === '') {
            return;
        }

        // Strip leading "dply " prefix so users can paste full commands.
        $args = preg_replace('/^dply\s+/', '', $raw);

        $token = $this->sessionToken();
        $binary = $this->cliBinary();

        if (! $binary) {
            $this->history[] = [
                'cmd' => $raw,
                'out' => '',
                'exit' => null,
                'error' => 'CLI binary not found. Set DPLY_CLI_BINARY in .env.',
            ];

            return;
        }

        // Split the args string into argv tokens, respecting quoted strings.
        preg_match_all('/\'[^\']*\'|"[^"]*"|\S+/', $args, $matches);
        $argv = array_map(static fn (string $t): string => trim($t, '\'"'), $matches[0]);

        $result = Process::env([
            'DPLY_TOKEN' => $token,
            'DPLY_HOST' => config('app.url'),
            'NO_COLOR' => '1',
        ])->timeout(30)->run([$binary, ...$argv, '--no-interaction']);

        $out = $result->output();
        $err = $result->errorOutput();

        $this->history[] = [
            'cmd' => $raw,
            'out' => rtrim($out),
            'exit' => $result->exitCode(),
            'error' => $err !== '' ? rtrim($err) : null,
        ];

        // Keep last 30 entries.
        if (count($this->history) > 30) {
            $this->history = array_slice($this->history, -30);
        }

        $this->input = '';

        $this->dispatch('cli-console-ran');
    }

    public function clearHistory(): void
    {
        $this->history = [];
    }

    public function prefill(string $command): void
    {
        $this->input = $command;
    }

    public function render(): View
    {
        return view('livewire.sites.cli-console', [
            'cliBinary' => $this->cliBinary(),
        ]);
    }

    private function sessionToken(): string
    {
        $key = 'dply.cli_console.token.'.$this->site->id;
        $stored = session($key);

        if (is_string($stored) && $stored !== '') {
            return $stored;
        }

        $user = auth()->user();
        $org = $user->currentOrganization();

        ['plaintext' => $plaintext] = ApiToken::createToken(
            $user,
            $org,
            'CLI console ('.$this->site->slug.')',
            expiresAt: now()->addHours(2),
            abilities: self::TOKEN_ABILITIES,
        );

        session([$key => $plaintext]);

        return $plaintext;
    }

    private function cliBinary(): ?string
    {
        $configured = config('dply.cli_binary');
        if ($configured && file_exists($configured)) {
            return $configured;
        }

        // Dev default: sibling dply-cli repo.
        $devPath = base_path('../dply-cli/dply');
        if (file_exists($devPath)) {
            return realpath($devPath) ?: null;
        }

        return null;
    }
}
