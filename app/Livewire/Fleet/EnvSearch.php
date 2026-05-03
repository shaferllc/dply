<?php

declare(strict_types=1);

namespace App\Livewire\Fleet;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteEnvironmentVariable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Browser view of dply:fleet:env-find. Search every site's env
 * variables by exact key OR by prefix. Useful for "where did I
 * leave DATABASE_URL" investigations and "show me every AWS_*"
 * audit sweeps.
 *
 * Values are masked by default; --reveal-equivalent toggle is
 * gated to require an explicit click since this is shared infra.
 *
 * Org-scoped — only shows sites in the user's current
 * organization.
 */
class EnvSearch extends Component
{
    #[Url(as: 'q', except: '')]
    public string $query = '';

    #[Url(as: 'mode', except: 'exact')]
    public string $mode = 'exact';

    public bool $reveal = false;

    public function toggleReveal(): void
    {
        $this->reveal = ! $this->reveal;
    }

    public function render(): View
    {
        $org = auth()->user()?->currentOrganization();
        abort_if($org === null, 403);

        $serverIds = Server::query()
            ->where('organization_id', $org->id)
            ->pluck('id');
        $siteIdToName = Site::query()
            ->whereIn('server_id', $serverIds)
            ->get(['id', 'name', 'slug', 'server_id'])
            ->keyBy('id');

        $rows = [];
        if ($this->query !== '') {
            $needle = trim($this->query);
            $envQuery = SiteEnvironmentVariable::query()
                ->whereIn('site_id', $siteIdToName->keys())
                ->select(['id', 'site_id', 'env_key', 'env_value', 'environment']);

            if ($this->mode === 'prefix') {
                $envQuery->where('env_key', 'like', $this->escapeLike($needle).'%');
            } else {
                $envQuery->where('env_key', $needle);
            }
            $matches = $envQuery->get();

            foreach ($matches as $match) {
                $site = $siteIdToName->get($match->site_id);
                if ($site === null) {
                    continue;
                }
                $value = (string) $match->env_value;
                $rows[] = [
                    'site' => $site,
                    'environment' => $match->environment,
                    'key' => $match->env_key,
                    'value' => $this->reveal ? $value : $this->mask($value),
                ];
            }
            usort($rows, function ($a, $b) {
                return [$a['site']->name, $a['environment'], $a['key']]
                    <=> [$b['site']->name, $b['environment'], $b['key']];
            });
        }

        return view('livewire.fleet.env-search', [
            'rows' => $rows,
            'hasQuery' => $this->query !== '',
        ])->layout('layouts.app');
    }

    private function escapeLike(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
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
