<?php

declare(strict_types=1);

namespace App\Livewire\Infrastructure;

use App\Services\Infrastructure\DeployContractCatalog;
use Illuminate\Contracts\View\View;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Org-wide deploy contract status for Edge previews awaiting promote.
 */
class DeployContracts extends Component
{
    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'filter', except: '')]
    public string $filter = '';

    public function render(DeployContractCatalog $catalog): View
    {
        $org = auth()->user()?->currentOrganization();
        abort_if($org === null, 403);

        $rows = $catalog->forOrganization($org);
        $counts = $catalog->counts($org);

        if ($this->filter === 'blocked') {
            $rows = array_values(array_filter($rows, fn (array $r): bool => ! $r['ready'] && $r['status'] !== null));
        } elseif ($this->filter === 'not_run') {
            $rows = array_values(array_filter($rows, fn (array $r): bool => $r['status'] === null));
        } elseif ($this->filter === 'ready') {
            $rows = array_values(array_filter($rows, fn (array $r): bool => $r['ready']));
        }

        if ($this->search !== '') {
            $needle = strtolower(trim($this->search));
            $rows = array_values(array_filter(
                $rows,
                fn (array $r): bool => str_contains(strtolower($r['preview_name']), $needle)
                    || str_contains(strtolower($r['parent_name']), $needle)
                    || str_contains(strtolower((string) ($r['branch'] ?? '')), $needle),
            ));
        }

        return view('livewire.infrastructure.deploy-contracts', [
            'rows' => $rows,
            'counts' => $counts,
            'contractEnabled' => Feature::active('global.deploy_contract'),
        ])->layout('layouts.app');
    }
}
