@php
    $tabs = \App\Support\Admin\AdminFeatureFlags::productLineSlugs();
@endphp

<div>
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4 border-b border-brand-ink/10 pb-6">
        <div>
            <x-page-header
                :title="$organization->name"
                :description="__('Per-org Pennant overrides by product line. Emergency global kill switches are not overridable here.')"
                flush
                compact
            />
            <p class="mt-2 text-xs text-brand-mist">
                {{ __(':count explicit overrides · ID :id', ['count' => number_format($overrideCount), 'id' => $organization->id]) }}
            </p>
        </div>
        <a href="{{ route('organizations.show', $organization) }}" wire:navigate class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-medium shadow-sm hover:bg-brand-sand/40">
            {{ __('Open in app') }}
            <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4 text-brand-moss" />
        </a>
    </div>

    <div class="mb-6 flex flex-wrap gap-2">
        @foreach ($tabs as $slug => $label)
            <button
                type="button"
                wire:click="setTab('{{ $slug }}')"
                @class([
                    'rounded-lg px-3 py-2 text-sm font-medium transition',
                    $tab === $slug ? 'bg-brand-sand/70 text-brand-ink border border-brand-ink/10 shadow-sm' : 'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink border border-transparent',
                ])
            >
                {{ $label }}
            </button>
        @endforeach
    </div>

    <div class="space-y-4">
        @forelse ($groups as $group)
            <details class="dply-card-compact group" @if($loop->first) open @endif wire:key="org-group-{{ $group['title'] }}">
                <summary class="cursor-pointer list-none font-semibold text-brand-ink marker:content-none [&::-webkit-details-marker]:hidden">
                    <span class="flex items-center justify-between gap-2">
                        {{ $group['title'] }}
                        <x-heroicon-o-chevron-down class="h-5 w-5 text-brand-moss transition group-open:rotate-180" />
                    </span>
                </summary>
                <ul class="mt-4 space-y-2">
                    @foreach ($group['flags'] as $flag)
                        <li wire:key="org-flag-{{ $flag['key'] }}">
                            <x-admin-flag-row :flag="$flag" mode="org">
                                <input type="checkbox" wire:click="toggleOrgFeatureFlag('{{ $flag['key'] }}')" wire:loading.attr="disabled" @checked($flag['active']) class="h-4 w-4 shrink-0 rounded border-brand-ink/30 text-brand-sage focus:ring-brand-sage" />
                            </x-admin-flag-row>
                        </li>
                    @endforeach
                </ul>
            </details>
        @empty
            <p class="rounded-xl border border-dashed border-brand-ink/15 px-4 py-6 text-sm text-brand-moss">{{ __('No org-scoped flags for this product line.') }}</p>
        @endforelse
    </div>
</div>
