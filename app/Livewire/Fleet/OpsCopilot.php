<?php

declare(strict_types=1);

namespace App\Livewire\Fleet;

use App\Livewire\Concerns\RequiresFeature;
use App\Models\Server;
use App\Models\Site;
use App\Services\OpsCopilot\OpsCopilotContextBuilder;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Ops Copilot — cross-engine deploy failure triage. Assembles log excerpts,
 * repo config, intelligence alerts, and heuristic fix suggestions for
 * vibe-coded repos that need an ops layer beyond the AI builder.
 */
class OpsCopilot extends Component
{
    use RequiresFeature;

    protected string $requiredFeature = 'global.ops_copilot';

    #[Url(as: 'site', except: '')]
    public string $siteId = '';

    public function render(OpsCopilotContextBuilder $builder): View
    {
        $org = auth()->user()?->currentOrganization();
        abort_if($org === null, 403);

        $candidates = $builder->candidateSites($org);
        $context = null;
        $selectedSite = null;

        if ($this->siteId !== '') {
            $serverIds = Server::query()
                ->where('organization_id', $org->id)
                ->pluck('id');

            $selectedSite = Site::query()
                ->whereIn('server_id', $serverIds)
                ->whereKey($this->siteId)
                ->first();

            if ($selectedSite !== null) {
                $context = $builder->build($org, $selectedSite);
            }
        }

        return view('livewire.fleet.ops-copilot', [
            'org' => $org,
            'candidates' => $candidates,
            'selectedSite' => $selectedSite,
            'context' => $context,
        ])->layout('layouts.app');
    }
}
