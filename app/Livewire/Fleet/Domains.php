<?php

declare(strict_types=1);

namespace App\Livewire\Fleet;

use App\Livewire\Concerns\RequiresFeature;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Fleet-wide domain inventory. Browser view of dply:fleet:domain-list
 * with a search input that mirrors dply:fleet:domain-find --contains.
 *
 * Useful for DNS audits, certificate sanity checks, and "who's
 * serving example.com?" investigations. Org-scoped — only shows
 * domains attached to sites in the user's current organization.
 */
class Domains extends Component
{
    use RequiresFeature;

    protected string $requiredFeature = 'surface.fleet';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'runtime', except: '')]
    public string $runtimeFilter = '';

    #[Url(as: 'primary_only', except: false)]
    public bool $primaryOnly = false;

    public function clearFilters(): void
    {
        $this->search = '';
        $this->runtimeFilter = '';
        $this->primaryOnly = false;
    }

    public function render(): View
    {
        $org = auth()->user()?->currentOrganization();
        abort_if($org === null, 403);

        $serverIds = Server::query()
            ->where('organization_id', $org->id)
            ->pluck('id');

        $siteQuery = Site::query()->whereIn('server_id', $serverIds);
        if ($this->runtimeFilter !== '') {
            $siteQuery->where('runtime', $this->runtimeFilter);
        }
        $sites = $siteQuery->get(['id', 'name', 'slug', 'server_id', 'runtime'])->keyBy('id');

        $domainQuery = SiteDomain::query()
            ->whereIn('site_id', $sites->pluck('id'))
            ->orderBy('hostname');
        if ($this->primaryOnly) {
            $domainQuery->where('is_primary', true);
        }
        if ($this->search !== '') {
            $needle = strtolower(trim($this->search));
            $needle = (string) preg_replace('#^https?://#', '', $needle);
            $domainQuery->where('hostname', 'like', '%'.$this->escapeLike($needle).'%');
        }
        $domains = $domainQuery->get(['id', 'site_id', 'hostname', 'is_primary']);

        $servers = Server::query()
            ->whereIn('id', $sites->pluck('server_id')->filter()->unique())
            ->get(['id', 'name'])
            ->keyBy('id');

        $rows = [];
        foreach ($domains as $domain) {
            $site = $sites->get($domain->site_id);
            if ($site === null) {
                continue;
            }
            $server = $site->server_id ? $servers->get($site->server_id) : null;
            $rows[] = [
                'hostname' => $domain->hostname,
                'is_primary' => (bool) $domain->is_primary,
                'site' => $site,
                'server' => $server,
            ];
        }

        $runtimes = $sites->pluck('runtime')->filter()->unique()->values()->all();
        sort($runtimes);

        return view('livewire.fleet.domains', [
            'rows' => $rows,
            'runtimes' => $runtimes,
        ])->layout('layouts.app');
    }

    private function escapeLike(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
    }
}
