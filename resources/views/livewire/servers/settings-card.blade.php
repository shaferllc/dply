@php
    $card = 'border-b border-brand-ink/10';
    $settingsDescription = __('Navigate through the tabs to manage different settings categories. Changes in each section are automatically saved.');
@endphp

{{-- Single root: this is a nested component, so the card is the whole render. --}}
<div class="min-w-0">
    <section class="dply-card min-w-0 overflow-hidden p-0">
        <x-workspace-panel-head
            dense
            icon="heroicon-o-cog-8-tooth"
            :title="__('Settings')"
            :note="$settingsDescription"
            class="border-b border-brand-ink/10"
        />

        <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4">
            @include('livewire.servers.partials.settings.tabs', [
                'server' => $server,
                'section' => $section,
                'settingsTabs' => $settingsTabs,
            ])
        </div>

        {{-- Skeleton while a category switch is in flight, then the panel. Only
             the card re-renders now, so this is a short swap rather than the
             page-sized reload the strip used to cause.

             wire:key includes the section, and that part is load-bearing rather
             than cosmetic. Sections have wildly different DOM — Export is one
             button, Notes is a stack of cards — and asking Livewire's morph to
             patch one into the other made it throw:

               TypeError: Cannot read properties of null (reading 'before')
                 at Block.appendChild → patchChildren → morph

             It chokes on the @if/@foreach block markers. A thrown morph leaves
             the DOM half-patched and the component dead, which is exactly the
             "stops working after a click or two" wedge — and why guarding the
             clicks only ever narrowed the window instead of fixing it. A key
             that changes with the section makes Livewire discard and rebuild the
             subtree instead of patching across two incompatible shapes. --}}
        {{-- wire:loading.block, not bare wire:loading: Livewire reveals loading
             targets as display:inline-block, which shrink-wraps this wrapper to
             its widest bar — the skeleton rendered at roughly a third of the
             card. .block makes it fill the card like the panel it stands in for. --}}
        {{-- Note the shape: the OUTER wrappers are stable and carry the loading
             directives; the section key lives on the INNER div.

             The key is what stops Livewire's morph throwing when it tries to
             patch one section's DOM into another's. But keying the outer wrapper
             meant that on a switch Livewire replaced that keyed div and left the
             previous section's subtree behind as a sibling — outside the wrapper
             it had just hidden. The old panel stayed on screen underneath the
             skeleton. Keeping the key inside a stable wrapper means any such
             leftover is inside the hidden element, so it can't show through. --}}
        <div wire:loading.block wire:target="setSection" aria-busy="true" aria-live="polite">
            <span class="sr-only">{{ __('Loading section…') }}</span>
            <div wire:key="settings-skeleton-{{ $section }}">
                @include('livewire.servers.partials.settings-section-skeleton', ['section' => $section])
            </div>
        </div>

        <div class="min-w-0" wire:loading.remove wire:target="setSection">
            <div wire:key="settings-section-{{ $section }}">
                @include('livewire.servers.partials.settings-tab', [
                    'workspaces' => $workspaces,
                    'card' => $card,
                    'section' => $section,
                    'costReport' => $costReport ?? null,
                ])
            </div>
        </div>
    </section>

    {{-- The removal flow's state lives on this component, so its modal renders
         here rather than in the page layout's modals slot. Fixed-position
         overlay, so nesting it inside the card is only a DOM detail. --}}
    @include('livewire.servers.partials.remove-server-modal', [
        'open' => $showRemoveServerModal,
        'serverName' => $server->name,
        'serverId' => $server->id,
        'deletionSummary' => $deletionSummary,
    ])
</div>
