<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteEnvironmentVariable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Browser view of dply:site:env-diff. Compares two environment
 * scopes for a site and renders three buckets:
 *   - only in 'from'
 *   - only in 'to'
 *   - keys in both with different values
 *
 * Values are masked with • dots by default for the same reason
 * env-list is masked: scrollback safety. The "Reveal" toggle
 * surfaces cleartext but resets on navigation away.
 */
class EnvDiff extends Component
{
    public Server $server;

    public Site $site;

    #[Url(as: 'from')]
    public string $fromEnv = 'production';

    #[Url(as: 'to')]
    public string $toEnv = 'staging';

    public bool $reveal = false;

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
    }

    public function toggleReveal(): void
    {
        $this->reveal = ! $this->reveal;
    }

    public function render(): View
    {
        $fromVars = $this->loadVars($this->fromEnv);
        $toVars = $this->loadVars($this->toEnv);

        $onlyInFrom = array_diff_key($fromVars, $toVars);
        $onlyInTo = array_diff_key($toVars, $fromVars);
        $shared = array_intersect_key($fromVars, $toVars);
        $differs = [];
        foreach ($shared as $key => $fromVal) {
            $toVal = $toVars[$key];
            if ($fromVal !== $toVal) {
                $differs[$key] = [
                    'from' => $this->reveal ? $fromVal : $this->mask($fromVal),
                    'to' => $this->reveal ? $toVal : $this->mask($toVal),
                ];
            }
        }
        ksort($onlyInFrom);
        ksort($onlyInTo);
        ksort($differs);

        $availableEnvs = SiteEnvironmentVariable::query()
            ->where('site_id', $this->site->id)
            ->distinct()
            ->orderBy('environment')
            ->pluck('environment')
            ->all();

        return view('livewire.sites.env-diff', [
            'onlyInFrom' => array_keys($onlyInFrom),
            'onlyInTo' => array_keys($onlyInTo),
            'differs' => $differs,
            'availableEnvs' => $availableEnvs,
            'inSync' => $onlyInFrom === [] && $onlyInTo === [] && $differs === [],
        ])->layout('layouts.app');
    }

    /**
     * @return array<string, string>
     */
    private function loadVars(string $environment): array
    {
        $rows = SiteEnvironmentVariable::query()
            ->where('site_id', $this->site->id)
            ->where('environment', $environment)
            ->get(['env_key', 'env_value']);

        $out = [];
        foreach ($rows as $r) {
            $out[$r->env_key] = (string) $r->env_value;
        }

        return $out;
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
