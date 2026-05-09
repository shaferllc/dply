<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Models\Server;
use App\Models\Site;
use App\Services\Sites\DotEnvFileParser;
use App\Services\Sites\SiteEnvReader;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Browser view of dply:site:env-diff. Compares Dply's encrypted cache to the
 * live `.env` file on the server and renders three buckets:
 *   - only in cache (the operator added it locally but never pushed)
 *   - only on server (the server has keys our cache doesn't — drift)
 *   - keys in both with different values
 *
 * Replaces the previous prod/staging axis (which went away when we collapsed
 * site_environment_variables into a single per-site cache).
 *
 * Values are masked with • dots by default; the "Reveal" toggle surfaces
 * cleartext but resets on navigation away.
 */
class EnvDiff extends Component
{
    public Server $server;

    public Site $site;

    public bool $reveal = false;

    /** Set to true when this runtime has no server file to diff against. */
    public bool $unsupported = false;

    /** Populated on render() when the SSH read succeeds. */
    public string $serverError = '';

    public function mount(Server $server, Site $site): void
    {
        if ($site->server_id !== $server->id) {
            abort(404);
        }
        if ($server->organization_id !== auth()->user()?->currentOrganization()?->id) {
            abort(404);
        }

        $this->server = $server;
        $this->site = $site;
        $this->unsupported = ! $server->hostCapabilities()->supportsEnvPushToHost();
    }

    public function toggleReveal(): void
    {
        $this->reveal = ! $this->reveal;
    }

    public function render(SiteEnvReader $reader, DotEnvFileParser $parser): View
    {
        $cacheVars = $parser->parse((string) ($this->site->env_file_content ?? ''))['variables'];
        $serverVars = [];

        if (! $this->unsupported) {
            try {
                $serverVars = $parser->parse($reader->read($this->site))['variables'];
            } catch (\Throwable $e) {
                $this->serverError = $e->getMessage();
            }
        }

        $onlyInCache = array_diff_key($cacheVars, $serverVars);
        $onlyInServer = array_diff_key($serverVars, $cacheVars);
        $shared = array_intersect_key($cacheVars, $serverVars);
        $differs = [];
        foreach ($shared as $key => $cacheVal) {
            $serverVal = $serverVars[$key];
            if ($cacheVal !== $serverVal) {
                $differs[$key] = [
                    'cache' => $this->reveal ? $cacheVal : $this->mask($cacheVal),
                    'server' => $this->reveal ? $serverVal : $this->mask($serverVal),
                ];
            }
        }
        ksort($onlyInCache);
        ksort($onlyInServer);
        ksort($differs);

        return view('livewire.sites.env-diff', [
            'onlyInCache' => array_keys($onlyInCache),
            'onlyInServer' => array_keys($onlyInServer),
            'differs' => $differs,
            'inSync' => $onlyInCache === [] && $onlyInServer === [] && $differs === [] && $this->serverError === '',
        ])->layout('layouts.app');
    }

    private function mask(string $value): string
    {
        $len = strlen($value);
        if ($len === 0) {
            return '(empty)';
        }
        if ($len <= 6) {
            return str_repeat('•', $len);
        }

        return substr($value, 0, 2).str_repeat('•', max(4, $len - 4)).substr($value, -2);
    }
}
