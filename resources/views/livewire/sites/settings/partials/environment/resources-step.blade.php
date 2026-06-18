{{-- First-deploy SETUP wizard → Resources step. Detection-driven suggestions
     from ResourceSuggestionMapper: connect a database / Redis / object storage /
     mail / broadcasting before the Environment step so each binding adopts (and
     strips) its own keys from the variables editor. Dual-path satisfaction — a
     card is "done" when its binding is connected OR every owned key is set by
     hand. Reuses the shared site-binding-modal (bindingModalOnly include). --}}
@php $suggestions = $this->resourceSuggestions(); @endphp

<div class="space-y-5">
    <div class="rounded-2xl border border-brand-ink/10 bg-white/80 p-6 shadow-sm sm:p-8">
        <div class="flex items-center gap-3">
            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-brand-sage/12 text-brand-forest">
                <x-heroicon-o-squares-2x2 class="h-5 w-5" />
            </div>
            <div class="min-w-0">
                <h2 class="text-lg font-semibold text-brand-ink">{{ __('Connect resources') }}</h2>
                <p class="text-sm text-brand-moss">{{ __('We detected what :name needs from its code. Connect each one and its connection variables are managed for you.', ['name' => $site->name]) }}</p>
            </div>
        </div>

        <ul class="mt-6 space-y-3">
            @foreach ($suggestions as $s)
                @php $satisfied = (bool) ($s['satisfied'] ?? false); @endphp
                <li @class([
                    'flex flex-col gap-3 rounded-xl border p-4 sm:flex-row sm:items-center sm:justify-between',
                    'border-brand-sage/40 bg-brand-sage/[0.06]' => $satisfied,
                    'border-brand-ink/10 bg-white' => ! $satisfied,
                ])>
                    <div class="flex min-w-0 items-start gap-3">
                        <div @class([
                            'mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg',
                            'bg-brand-forest/10 text-brand-forest' => $satisfied,
                            'bg-brand-ink/[0.05] text-brand-moss' => ! $satisfied,
                        ])>
                            <x-dynamic-component :component="$s['icon']" class="h-5 w-5" />
                        </div>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="text-sm font-semibold text-brand-ink">{{ $s['label'] }}</p>
                                @if ($satisfied)
                                    <span class="inline-flex items-center gap-1 rounded-full bg-brand-forest/10 px-2 py-0.5 text-[11px] font-medium text-brand-forest">
                                        <x-heroicon-s-check class="h-3 w-3" />
                                        {{ ($s['has_binding'] ?? false) ? __('Connected') : __('Set via variables') }}
                                    </span>
                                @endif
                            </div>
                            <p class="mt-0.5 text-xs text-brand-moss">{{ $s['description'] }}</p>
                            @if (! empty($s['note']) && ! $satisfied)
                                <p class="mt-1 text-[11px] text-brand-mist">{{ $s['note'] }}</p>
                            @endif
                            @if (! empty($s['matched_keys']))
                                <p class="mt-1.5 truncate font-mono text-[11px] text-brand-mist" title="{{ implode(', ', $s['matched_keys']) }}">{{ implode(', ', array_slice($s['matched_keys'], 0, 6)) }}{{ count($s['matched_keys']) > 6 ? ', …' : '' }}</p>
                            @endif
                        </div>
                    </div>

                    <div class="shrink-0 sm:pl-4">
                        @if ($satisfied)
                            <button type="button" wire:click="connectSuggestedResource('{{ $s['type'] }}')"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 px-3 py-1.5 text-xs font-medium text-brand-moss transition-colors hover:bg-brand-sand/40 hover:text-brand-ink">
                                {{ ($s['has_binding'] ?? false) ? __('Reconfigure') : __('Connect anyway') }}
                            </button>
                        @else
                            <button type="button" wire:click="connectSuggestedResource('{{ $s['type'] }}')"
                                class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-medium text-brand-cream transition-colors hover:bg-brand-forest">
                                <x-heroicon-o-plus class="h-4 w-4" />
                                {{ $s['headline'] }}
                            </button>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>

        <p class="mt-4 text-xs text-brand-moss">
            {{ __('Prefer to wire these by hand? Skip ahead — any resource you leave unconnected just shows up as plain variables in the next step.') }}
        </p>
    </div>

    <div class="flex items-center justify-end">
        <button type="button" wire:click="goToStep('environment')"
            class="inline-flex items-center gap-2 rounded-lg bg-brand-ink px-4 py-2 text-sm font-medium text-brand-cream hover:bg-brand-forest">
            {{ __('Continue to variables') }} <x-heroicon-o-arrow-right class="h-4 w-4" />
        </button>
    </div>
</div>

{{-- The shared binding modal (attach/provision forms for every type). Rendered
     here so "Connect" works from the Resources step without the full env
     partial. bindingModalOnly suppresses the connected-bindings list. --}}
@include('livewire.sites.settings.partials.environment.resources', ['bindingModalOnly' => true])
