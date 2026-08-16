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
 * In-browser dply CLI runner — runs packages/dply-cli against this app's API
 * using a short-lived session token.
 */
class CliConsole extends Component
{
    public Site $site;

    public Server $server;

    public string $input = '';

    /** @var list<array{cmd: string, out: string, exit: int|null, error: string|null}> */
    public array $history = [];

    /** Abilities the local session token receives — enough for site read + deploy. */
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
        $this->input = 'site show --site '.$site->id;
    }

    public function run(): void
    {
        $raw = trim($this->input);
        if ($raw === '') {
            return;
        }

        // Strip leading "dply " prefix so users can paste full commands.
        $args = preg_replace('/^dply\s+/i', '', $raw) ?? $raw;

        $invocation = $this->cliInvocation();
        if ($invocation === null) {
            $this->history[] = [
                'cmd' => $raw,
                'out' => '',
                'exit' => null,
                'error' => 'CLI not found. Expected packages/dply-cli/bin/dply.mjs (Node 18+) or set DPLY_CLI_BINARY.',
            ];

            return;
        }

        $auth = $this->apiAuth();
        if ($auth === null) {
            $this->history[] = [
                'cmd' => $raw,
                'out' => '',
                'exit' => null,
                'error' => 'Could not mint a short-lived API token for this console.',
            ];

            return;
        }

        preg_match_all('/\'[^\']*\'|"[^"]*"|\S+/', $args, $matches);
        $argv = array_map(static fn (string $t): string => trim($t, '\'"'), $matches[0]);

        $result = Process::env([
            'DPLY_TOKEN' => $auth['token'],
            'DPLY_API_TOKEN' => $auth['token'],
            'DPLY_API_BASE_URL' => $auth['base_url'],
            'DPLY_BASE_URL' => $auth['base_url'],
            'DPLY_HOST' => $auth['base_url'],
            'DPLY_SITE' => $this->site->id,
            'NO_COLOR' => '1',
            'PATH' => (string) getenv('PATH'),
        ])->timeout(60)->run([...$invocation, ...$argv]);

        $out = $result->output();
        $err = $result->errorOutput();

        $this->history[] = [
            'cmd' => $raw,
            'out' => rtrim($out),
            'exit' => $result->exitCode(),
            'error' => $err !== '' ? rtrim($err) : null,
        ];

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
            'cliReady' => $this->cliInvocation() !== null,
            'apiHost' => $this->apiAuth()['base_url'] ?? config('app.url'),
            'presetCommands' => $this->presetCommands(),
        ]);
    }

    /**
     * @return list<array{label: string, command: string}>
     */
    private function presetCommands(): array
    {
        $id = $this->site->id;

        return [
            ['label' => __('Show'), 'command' => 'site show --site '.$id],
            ['label' => __('Status'), 'command' => 'site status --site '.$id],
            ['label' => __('Deployments'), 'command' => 'site deployments --site '.$id],
            ['label' => __('Logs'), 'command' => 'site logs --site '.$id],
            ['label' => __('Deploy'), 'command' => 'site deploy --site '.$id],
            ['label' => __('List sites'), 'command' => 'site list'],
        ];
    }

    /**
     * @return array{token: string, base_url: string}|null
     */
    private function apiAuth(): ?array
    {
        return [
            'token' => $this->sessionToken(),
            'base_url' => rtrim((string) config('app.url'), '/'),
        ];
    }

    private function sessionToken(): string
    {
        $key = 'dply.cli_console.token.'.$this->site->id;
        $stored = session($key);

        if (is_string($stored) && $stored !== '') {
            return $stored;
        }

        $user = auth()->user();
        $org = $user?->currentOrganization();
        if ($user === null || $org === null) {
            return '';
        }

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

    private function looksLikeWriteCommand(string $args): bool
    {
        $normalized = strtolower(trim($args));

        return (bool) preg_match(
            '/^(site\s+deploy|deploy\b|site\s+env\s+(set|rm|push)|edge\s+(deploy|promote|rollback|purge|env\s+(set|rm|push)))\b/',
            $normalized,
        );
    }

    /**
     * @return list<string>|null argv prefix to invoke the CLI (`node …/dply.mjs` or a binary)
     */
    private function cliInvocation(): ?array
    {
        $configured = config('dply.cli_binary');
        if (is_string($configured) && $configured !== '' && is_file($configured)) {
            return str_ends_with($configured, '.mjs')
                ? [$this->nodeBinary(), $configured]
                : [$configured];
        }

        $package = base_path('packages/dply-cli/bin/dply.mjs');
        if (is_file($package)) {
            return [$this->nodeBinary(), $package];
        }

        $sibling = base_path('../dply-cli/bin/dply.mjs');
        if (is_file($sibling)) {
            return [$this->nodeBinary(), (string) realpath($sibling)];
        }

        $legacySibling = base_path('../dply-cli/dply');
        if (is_file($legacySibling)) {
            return [(string) realpath($legacySibling)];
        }

        return null;
    }

    private function nodeBinary(): string
    {
        $fromEnv = getenv('DPLY_NODE_BINARY');
        if (is_string($fromEnv) && $fromEnv !== '' && is_file($fromEnv)) {
            return $fromEnv;
        }

        foreach (['/usr/local/bin/node', '/opt/homebrew/bin/node', (string) getenv('HOME').'/.local/bin/node'] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return 'node';
    }
}
