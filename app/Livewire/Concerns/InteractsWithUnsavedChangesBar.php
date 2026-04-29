<?php

namespace App\Livewire\Concerns;

/**
 * Marker for Livewire pages that render the unsaved-changes-bar Blade component.
 *
 * Implement discard actions referenced by the bar by reloading persisted state (same as after mount).
 * Use wire:target on the bar when one component holds multiple independent forms.
 */
trait InteractsWithUnsavedChangesBar {}
