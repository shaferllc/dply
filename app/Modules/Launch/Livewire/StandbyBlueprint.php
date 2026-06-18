<?php

declare(strict_types=1);

namespace App\Modules\Launch\Livewire;

use App\Modules\Launch\Services\StandbyBlueprintPlanner;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Tier C workflow: opinionated multi-engine standby / failover playbooks
 * merged with org inventory and deep links — not full HA.
 */
#[Layout('layouts.app')]
class StandbyBlueprint extends Component
{
    #[Url(as: 'blueprint', except: '')]
    public string $blueprintKey = '';

    public function mount(): void
    {
        abort_unless(standby_blueprint_active(), 404);
    }

    public function selectBlueprint(string $key): void
    {
        $this->blueprintKey = $key;
    }

    public function backToCatalog(): void
    {
        $this->blueprintKey = '';
    }

    public function render(StandbyBlueprintPlanner $planner): View
    {
        $org = auth()->user()?->currentOrganization();
        abort_if($org === null, 403);

        $catalog = $planner->catalog($org);
        $playbook = $this->blueprintKey !== ''
            ? $planner->playbook($org, $this->blueprintKey)?->toArray()
            : null;

        return view('livewire.launches.standby-blueprint', [
            'catalog' => $catalog,
            'playbook' => $playbook,
        ]);
    }
}
