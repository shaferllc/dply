<?php

declare(strict_types=1);

namespace App\Livewire\Fleet;

use App\Livewire\Concerns\RequiresFeature;
use App\Services\Fleet\UnifiedPreviewCatalog;
use App\Support\Preview\UnifiedPreviewHostname;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Fleet-wide managed preview hostname inventory — BYO testing hostnames and
 * Edge delivery URLs on a unified label + apex pattern.
 */
class Previews extends Component
{
    use RequiresFeature;

    protected string $requiredFeature = 'surface.fleet';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'product', except: '')]
    public string $productFilter = '';

    public function render(UnifiedPreviewCatalog $catalog): View
    {
        $org = auth()->user()?->currentOrganization();
        abort_if($org === null, 403);

        $rows = $catalog->forOrganization($org);

        if ($this->productFilter !== '') {
            $rows = array_values(array_filter(
                $rows,
                fn (array $row): bool => $row['product'] === $this->productFilter,
            ));
        }

        if ($this->search !== '') {
            $needle = strtolower(trim($this->search));
            $rows = array_values(array_filter(
                $rows,
                fn (array $row): bool => str_contains($row['hostname'], $needle)
                    || str_contains(strtolower($row['site_name']), $needle)
                    || str_contains(strtolower((string) ($row['parent_name'] ?? '')), $needle),
            ));
        }

        $pattern = app(UnifiedPreviewHostname::class);

        return view('livewire.fleet.previews', [
            'rows' => $rows,
            'total' => count($catalog->forOrganization($org)),
            'patternPrimary' => '{slug}-{idHash8}.{apex}',
            'patternBranch' => '{parentLabel}--{branch|pr-n}.{apex}',
            'preferredApex' => $pattern->preferredApex(),
        ])->layout('layouts.app');
    }
}
