<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Livewire;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\FunctionAction;
use App\Models\Site;
use App\Modules\Serverless\Services\ServerlessGlueInventory;
use App\Modules\Serverless\Services\ServerlessGluePlanner;
use App\Modules\Serverless\Services\ServerlessSequenceBuilder;
use App\Modules\Serverless\Services\ServerlessSequenceDeployer;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Tier C workflow: multi-engine glue — recipe catalog plus OpenWhisk
 * sequence builder wired to Edge hooks, Cloud redeploy, and BYO crons.
 */
#[Layout('layouts.app')]
class Glue extends Component
{
    use DispatchesToastNotifications;

    /** recipes | sequences */
    #[Url(as: 'tab', except: 'recipes')]
    public string $tab = 'recipes';

    #[Url(as: 'recipe', except: '')]
    public string $recipeKey = '';

    #[Url(as: 'server', except: '')]
    public string $sequenceServerId = '';

    #[Url(as: 'site', except: '')]
    public string $sequenceSiteId = '';

    public string $sequenceName = '';

    /** @var list<string> */
    public array $sequenceComponentIds = ['', ''];

    public function mount(): void
    {
        abort_unless(Feature::active('surface.serverless'), 404);
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['recipes', 'sequences'], true) ? $tab : 'recipes';
    }

    public function selectRecipe(string $key): void
    {
        $this->recipeKey = $key;
        $this->tab = 'recipes';
    }

    public function backToCatalog(): void
    {
        $this->recipeKey = '';
    }

    public function updatedSequenceServerId(): void
    {
        $this->sequenceSiteId = '';
    }

    public function addSequenceStep(): void
    {
        $this->sequenceComponentIds[] = '';
    }

    public function removeSequenceStep(int $index): void
    {
        if (count($this->sequenceComponentIds) <= 2) {
            return;
        }

        unset($this->sequenceComponentIds[$index]);
        $this->sequenceComponentIds = array_values($this->sequenceComponentIds);
    }

    public function saveSequence(ServerlessSequenceBuilder $builder): void
    {
        $site = $this->sequenceSite();
        $this->authorize('update', $site);

        $this->validate([
            'sequenceName' => ['required', 'string', 'max:120'],
            'sequenceComponentIds' => ['required', 'array', 'min:2'],
            'sequenceComponentIds.*' => ['required', 'string'],
        ]);

        try {
            $builder->define($site, $this->sequenceName, $this->sequenceComponentIds);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['sequenceComponentIds' => $e->getMessage()]);
        }

        $this->reset(['sequenceName']);
        $this->sequenceComponentIds = ['', ''];
        $this->toastSuccess(__('Sequence saved.'));
    }

    public function deploySequence(string $actionId, ServerlessSequenceDeployer $deployer): void
    {
        $action = FunctionAction::query()
            ->with('site')
            ->whereKey($actionId)
            ->firstOrFail();

        $site = $action->site;
        abort_if($site === null, 404);
        $this->authorize('update', $site);

        $org = auth()->user()?->currentOrganization();
        abort_if($org === null || $site->organization_id !== $org->id, 403);

        $result = $deployer->deploy($action);

        if ($result['ok']) {
            $this->toastSuccess(__('Sequence deployed to OpenWhisk.'));
        } else {
            $this->toastError($result['error'] ?? __('Deploy failed.'));
        }
    }

    public function render(ServerlessGluePlanner $planner, ServerlessGlueInventory $inventory): View
    {
        $org = auth()->user()?->currentOrganization();
        abort_if($org === null, 403);

        $snapshot = $inventory->forOrganization($org);
        $recipe = $this->recipeKey !== ''
            ? $planner->recipe($org, $this->recipeKey)?->toArray()
            : null;

        $functionsHostIds = collect($snapshot['functions_hosts'])->pluck('id');

        $sequenceSites = $functionsHostIds->isEmpty()
            ? collect()
            : Site::query()
                ->where('organization_id', $org->id)
                ->whereIn('server_id', $functionsHostIds)
                ->when($this->sequenceServerId !== '', fn ($query) => $query->where('server_id', $this->sequenceServerId))
                ->orderBy('name')
                ->get(['id', 'name', 'server_id']);

        $codeActionsForServer = collect($snapshot['code_actions'])
            ->when($this->sequenceServerId !== '', fn ($rows) => $rows->where('server_id', $this->sequenceServerId))
            ->values()
            ->all();

        return view('livewire.serverless.glue', [
            'catalog' => $planner->catalog($org),
            'recipe' => $recipe,
            'snapshot' => $snapshot,
            'sequenceSites' => $sequenceSites,
            'codeActionsForServer' => $codeActionsForServer,
        ]);
    }

    private function sequenceSite(): Site
    {
        $org = auth()->user()?->currentOrganization();
        abort_if($org === null, 403);

        $site = Site::query()
            ->where('organization_id', $org->id)
            ->whereKey($this->sequenceSiteId)
            ->first();

        if ($site === null || $site->server_id === null) {
            throw ValidationException::withMessages(['sequenceSiteId' => __('Pick a package site on the functions host.')]);
        }

        $server = $site->server;
        if ($server === null || ! $server->isDigitalOceanFunctionsHost()) {
            throw ValidationException::withMessages(['sequenceSiteId' => __('Sequences must live on a DigitalOcean Functions host.')]);
        }

        return $site;
    }
}
