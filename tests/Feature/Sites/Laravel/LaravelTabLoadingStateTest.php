<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\Laravel\LaravelTabLoadingStateTest;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;

uses(RefreshDatabase::class);

/**
 * The workspace tab's inline spinner used to be gated behind the `icon` prop,
 * so icon-less tab strips (the Laravel sub-tabs) rendered no loading state at
 * all — clicking one looked frozen for the whole round-trip. The tabs opt out
 * of the global busy affordance via data-skip-busy, so this inline spinner is
 * the only feedback they get.
 */
test('an icon-less tab still renders a loading spinner bound to its action', function (): void {
    $html = Blade::render(
        '<x-server-workspace-tab :active="false" wire:click="setLaravelTab(\'octane\')">Octane</x-server-workspace-tab>'
    );

    expect($html)->toContain('wire:loading');
    expect($html)->toContain('wire:target="setLaravelTab(&#039;octane&#039;)"');
    // Opted out of the global busy overlay, so the inline spinner must exist.
    expect($html)->toContain('data-skip-busy="1"');
});

test('a tab with an icon swaps the icon for the spinner rather than adding one', function (): void {
    $html = Blade::render(
        '<x-server-workspace-tab icon="heroicon-o-clock" :active="false" wire:click="setTab(\'history\')">History</x-server-workspace-tab>'
    );

    expect($html)->toContain('wire:loading.remove');
    expect($html)->toContain('wire:loading');
    expect($html)->toContain('wire:target="setTab(&#039;history&#039;)"');
});

test('a tab with no wire:click renders no spinner', function (): void {
    $html = Blade::render('<x-server-workspace-tab :active="true">Static</x-server-workspace-tab>');

    expect($html)->not->toContain('wire:loading');
});
