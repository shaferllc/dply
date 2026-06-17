<?php

namespace App\Livewire\Concerns;

use Laravel\Pennant\Feature;
use Livewire\Component;

/**
 * Defense-in-depth flag check on every Livewire request (mount + hydrate).
 *
 * @phpstan-require-extends Component
 *
 * @property string $requiredFeature
 */
trait RequiresFeature
{
    public function bootedRequiresFeature(): void
    {
        $flag = $this->requiredFeature;
        if ($flag !== '' && ! Feature::active($flag)) {
            abort(404);
        }
    }
}
