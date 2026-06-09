<?php

declare(strict_types=1);

namespace App\Livewire\Fleet;

use App\Livewire\Concerns\RequiresFeature;
use App\Services\Fleet\BlastRadiusGraph;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Visual dependency map — "what breaks if X fails?" across servers,
 * BYO sites, Cloud origins, Edge fronts, and server databases.
 */
class BlastRadius extends Component
{
    use RequiresFeature;

    protected string $requiredFeature = 'surface.fleet';

    #[Url(as: 'focus', except: '')]
    public string $focusNodeId = '';

    public function render(): View
    {
        $org = auth()->user()?->currentOrganization();
        abort_if($org === null, 403);

        $graph = BlastRadiusGraph::forOrganization($org);
        $focused = $this->focusNodeId !== '' ? $graph->node($this->focusNodeId) : null;
        $affected = $focused !== null ? $graph->affectedBy($this->focusNodeId) : [];

        $nodesByLayer = [
            'infrastructure' => [],
            'applications' => [],
            'edge' => [],
        ];

        foreach ($graph->nodes() as $node) {
            $layer = match ($node['kind']) {
                'server', 'database' => 'infrastructure',
                'site' => ($node['product'] ?? '') === 'edge' ? 'edge' : 'applications',
                default => 'applications',
            };
            $nodesByLayer[$layer][] = $node;
        }

        return view('livewire.fleet.blast-radius', [
            'org' => $org,
            'graph' => $graph,
            'counts' => $graph->counts(),
            'nodesByLayer' => $nodesByLayer,
            'edges' => $graph->edges(),
            'focused' => $focused,
            'affected' => $affected,
        ])->layout('layouts.app');
    }
}
