<?php

declare(strict_types=1);

namespace App\Livewire\Edge;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Placeholder index for the dply Edge product line — JavaScript
 * frameworks, static sites, previews, and CDN-style delivery.
 */
#[Layout('layouts.app')]
class Index extends Component
{
    public function render(): View
    {
        $org = auth()->user()?->currentOrganization();
        abort_if($org === null, 403);

        return view('livewire.edge.index', [
            'org' => $org,
        ]);
    }
}
