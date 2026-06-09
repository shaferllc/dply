<?php

declare(strict_types=1);

namespace App\Livewire\Fleet;

use App\Livewire\Concerns\RequiresFeature;
use App\Models\EdgeSiteEnvVar;
use App\Models\Server;
use App\Models\Site;
use App\Services\Sites\DotEnvFileParser;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Fleet-wide env drift across sites that share a Git repo. Groups every
 * Site in the org by its source-control repo URL — BYO sites use
 * `git_repository_url`, Edge sites pull from `edge.source.repo` via
 * `Site::sourceControlRepositoryUrl()`. Each group with more than one
 * environment becomes a comparison matrix:
 *
 *   - rows: the union of env-var keys present in any environment
 *   - cols: each "environment" — BYO/Cloud site, Edge site production,
 *           Edge site preview
 *
 * Each cell is one of: same-as-baseline, missing, or differs (different
 * value than the first column).
 *
 * Org-scoped. Values are masked by default; reveal is a click-to-confirm
 * action since this is shared infra.
 */
class EnvDrift extends Component
{
    use RequiresFeature;

    protected string $requiredFeature = 'surface.fleet';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'hide_clean', except: false)]
    public bool $hideClean = false;

    public bool $reveal = false;

    public function toggleReveal(): void
    {
        $this->reveal = ! $this->reveal;
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->hideClean = false;
    }

    public function render(DotEnvFileParser $parser): View
    {
        $org = auth()->user()?->currentOrganization();
        abort_if($org === null, 403);

        $sites = Site::query()
            ->where(function ($q) use ($org): void {
                $q->where('organization_id', $org->id)
                    ->orWhereIn('server_id', Server::query()->where('organization_id', $org->id)->pluck('id'));
            })
            ->get(['id', 'name', 'slug', 'server_id', 'organization_id', 'git_repository_url', 'env_file_content', 'edge_backend', 'meta', 'deployment_environment', 'type', 'container_backend']);

        // Edge env-var rows for any Edge site in the org. Load once and
        // index by site_id so the per-site loop below stays O(sites).
        $edgeSiteIds = $sites
            ->filter(fn (Site $s) => $s->usesEdgeRuntime())
            ->pluck('id');
        $edgeVarsBySite = EdgeSiteEnvVar::query()
            ->whereIn('site_id', $edgeSiteIds)
            ->get(['site_id', 'key', 'scope', 'value_encrypted'])
            ->groupBy('site_id');

        $environments = [];
        foreach ($sites as $site) {
            $repo = $this->normalizeRepoKey($site->sourceControlRepositoryUrl());
            if ($repo === null) {
                continue;
            }

            if ($site->usesEdgeRuntime()) {
                $rows = $edgeVarsBySite->get($site->id, collect());
                $byScope = $rows->groupBy('scope');

                foreach ([EdgeSiteEnvVar::SCOPE_PRODUCTION, EdgeSiteEnvVar::SCOPE_PREVIEW] as $scope) {
                    $scopeRows = $byScope->get($scope, collect());
                    if ($scopeRows->isEmpty()) {
                        continue;
                    }
                    $vars = [];
                    foreach ($scopeRows as $row) {
                        $vars[$row->key] = (string) ($row->value ?? '');
                    }
                    $environments[] = [
                        'repo' => $repo,
                        'site' => $site,
                        'surface' => 'Edge',
                        'scope' => $scope === EdgeSiteEnvVar::SCOPE_PREVIEW ? 'preview' : 'production',
                        'vars' => $vars,
                    ];
                }

                continue;
            }

            $blob = (string) ($site->env_file_content ?? '');
            if ($blob === '') {
                continue;
            }
            $vars = $parser->parse($blob)['variables'];
            $environments[] = [
                'repo' => $repo,
                'site' => $site,
                'surface' => $site->container_backend ? 'Cloud' : 'BYO',
                'scope' => $site->deployment_environment ?: 'production',
                'vars' => $vars,
            ];
        }

        // Group by repo, keep only groups with more than one environment
        // since drift comparison needs a baseline.
        $groups = [];
        foreach ($environments as $env) {
            $groups[$env['repo']][] = $env;
        }
        $groups = array_filter($groups, fn (array $list) => count($list) > 1);

        if ($this->search !== '') {
            $needle = strtolower(trim($this->search));
            $groups = array_filter($groups, fn (string $repo) => str_contains(strtolower($repo), $needle), ARRAY_FILTER_USE_KEY);
        }

        $rendered = [];
        $cleanGroups = 0;
        foreach ($groups as $repo => $envs) {
            $matrix = $this->buildMatrix($envs);
            $hasDrift = $matrix['drift_keys'] > 0;
            if (! $hasDrift) {
                $cleanGroups++;
            }
            if ($this->hideClean && ! $hasDrift) {
                continue;
            }
            $rendered[] = [
                'repo' => $repo,
                'envs' => $envs,
                'matrix' => $matrix,
                'has_drift' => $hasDrift,
            ];
        }
        usort($rendered, function ($a, $b) {
            // Drifted groups first; within each, alphabetical by repo.
            $driftSort = ($b['has_drift'] ? 1 : 0) <=> ($a['has_drift'] ? 1 : 0);

            return $driftSort !== 0 ? $driftSort : strcmp($a['repo'], $b['repo']);
        });

        return view('livewire.fleet.env-drift', [
            'groups' => $rendered,
            'totalGroups' => count($groups),
            'cleanGroups' => $cleanGroups,
        ])->layout('layouts.app');
    }

    /**
     * Normalize a Git URL to a stable group key. Returns null when the
     * URL is empty so non-Git sites drop out of the comparison.
     */
    private function normalizeRepoKey(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        $url = preg_replace('#\.git$#', '', $url);
        $url = preg_replace('#^git@([^:]+):#', 'https://$1/', (string) $url);
        $url = preg_replace('#^https?://#', '', (string) $url);

        return strtolower((string) $url);
    }

    /**
     * Build a drift matrix for a single repo group. Returns the union of
     * keys across all environments plus a per-key per-env status code:
     *
     *   - 'missing'   key absent in this env
     *   - 'baseline'  present, same value as the first env that has it
     *   - 'differs'   present, value differs from the baseline
     *
     * @param  list<array{repo:string,site:Site,surface:string,scope:string,vars:array<string,string>}>  $envs
     * @return array{
     *     keys: list<string>,
     *     rows: array<string, list<array{status:string,value:string}>>,
     *     drift_keys: int,
     * }
     */
    private function buildMatrix(array $envs): array
    {
        $allKeys = [];
        foreach ($envs as $env) {
            foreach (array_keys($env['vars']) as $key) {
                $allKeys[$key] = true;
            }
        }
        $keys = array_keys($allKeys);
        sort($keys);

        $rows = [];
        $driftKeys = 0;
        foreach ($keys as $key) {
            $baseline = null;
            $rowHasDrift = false;
            $cells = [];
            foreach ($envs as $env) {
                if (! array_key_exists($key, $env['vars'])) {
                    $cells[] = ['status' => 'missing', 'value' => ''];
                    $rowHasDrift = true;

                    continue;
                }
                $value = (string) $env['vars'][$key];
                if ($baseline === null) {
                    $baseline = $value;
                    $cells[] = ['status' => 'baseline', 'value' => $value];

                    continue;
                }
                if ($value === $baseline) {
                    $cells[] = ['status' => 'baseline', 'value' => $value];
                } else {
                    $cells[] = ['status' => 'differs', 'value' => $value];
                    $rowHasDrift = true;
                }
            }
            $rows[$key] = $cells;
            if ($rowHasDrift) {
                $driftKeys++;
            }
        }

        return [
            'keys' => $keys,
            'rows' => $rows,
            'drift_keys' => $driftKeys,
        ];
    }

    public function maskValue(string $value): string
    {
        if ($this->reveal) {
            return $value;
        }
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
