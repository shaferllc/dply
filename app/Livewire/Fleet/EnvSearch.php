<?php

declare(strict_types=1);

namespace App\Livewire\Fleet;

use App\Models\Server;
use App\Models\Site;
use App\Services\Sites\DotEnvFileParser;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Browser view of dply:fleet:env-find. Searches every site's env cache by
 * exact key OR by prefix. Useful for "where did I leave DATABASE_URL"
 * investigations and "show me every AWS_*" audit sweeps.
 *
 * Since env values now live in the encrypted `sites.env_file_content` blob
 * (one per site), search runs in PHP after decrypting each blob — there is
 * no LIKE we can push to the DB. Acceptable for org-scale; if perf
 * regresses on very large fleets, follow up with a `site_env_keys` index
 * table.
 *
 * Values are masked by default; reveal toggle is gated to require an
 * explicit click since this is shared infra.
 *
 * Org-scoped — only shows sites in the user's current organization.
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

    public function render(DotEnvFileParser $parser): View
    {
        $org = auth()->user()?->currentOrganization();
        abort_if($org === null, 403);

        $serverIds = Server::query()
            ->where('organization_id', $org->id)
            ->pluck('id');
        $sites = Site::query()
            ->whereIn('server_id', $serverIds)
            ->whereNotNull('env_file_content')
            ->get(['id', 'name', 'slug', 'server_id', 'deployment_environment', 'env_file_content']);

        $rows = [];
        if ($this->query !== '') {
            $needle = trim($this->query);

            foreach ($sites as $site) {
                $vars = $parser->parse((string) ($site->env_file_content ?? ''))['variables'];
                foreach ($vars as $key => $value) {
                    $matches = $this->mode === 'prefix'
                        ? str_starts_with($key, $needle)
                        : $key === $needle;
                    if (! $matches) {
                        continue;
                    }
                    $rows[] = [
                        'site' => $site,
                        'environment' => (string) ($site->deployment_environment ?: 'production'),
                        'key' => $key,
                        'value' => $this->reveal ? $value : $this->mask($value),
                    ];
                }
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
