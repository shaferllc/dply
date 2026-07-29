<div>
    <x-page-header
        :title="__('All feature flags')"
        :description="__('Every Pennant flag in the app. Toggling here sets a platform-wide default that beats config/env for all scopes — an explicit per-org override still wins.')"
        flush
        compact
    />

    {{-- Filters --}}
    <section class="dply-card-compact mb-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
            <label class="flex-1">
                <span class="mb-1 block text-[11px] font-semibold uppercase tracking-[0.12em] text-brand-mist">{{ __('Search') }}</span>
                <input
                    type="search"
                    wire:model.live.debounce.250ms="search"
                    placeholder="{{ __('Filter by key or label…') }}"
                    class="w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                />
            </label>

            <label class="sm:w-56">
                <span class="mb-1 block text-[11px] font-semibold uppercase tracking-[0.12em] text-brand-mist">{{ __('Namespace') }}</span>
                <select
                    wire:model.live="namespace"
                    class="w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                >
                    <option value="">{{ __('All namespaces') }}</option>
                    @foreach ($namespaces as $ns)
                        <option value="{{ $ns }}">{{ $ns }}</option>
                    @endforeach
                </select>
            </label>

            <label class="flex items-center gap-2 pb-2 text-sm text-brand-moss">
                <input
                    type="checkbox"
                    wire:model.live="onlyOverridden"
                    class="h-4 w-4 rounded border-brand-ink/30 text-brand-sage focus:ring-brand-sage"
                />
                {{ __('Only overridden') }}
            </label>
        </div>

        <div class="mt-3 flex flex-wrap items-center gap-3 text-xs text-brand-mist">
            <span>{{ __(':shown of :total flags', ['shown' => $totalShown, 'total' => $totalFlags]) }}</span>
            @if ($overriddenCount > 0)
                <span class="inline-flex items-center gap-1 rounded-full bg-brand-rust/10 px-2 py-0.5 font-semibold text-brand-rust">
                    {{ __(':count platform override(s)', ['count' => $overriddenCount]) }}
                </span>
            @endif
            @if ($search !== '' || $namespace !== '' || $onlyOverridden)
                <button type="button" wire:click="resetFilters" class="font-medium text-brand-moss underline-offset-2 hover:underline">
                    {{ __('Reset filters') }}
                </button>
            @endif
        </div>
    </section>

    {{-- Flag groups --}}
    <div class="space-y-6">
        @forelse ($groups as $group)
            <section class="dply-card-compact" wire:key="ns-{{ $group['namespace'] }}">
                <div class="mb-3 flex items-baseline justify-between gap-3">
                    <h2 class="text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ $group['namespace'] }}</h2>
                    <span class="text-[10px] text-brand-mist">{{ trans_choice('{1} :count flag|[2,*] :count flags', count($group['flags']), ['count' => count($group['flags'])]) }}</span>
                </div>

                <ul class="grid gap-2 lg:grid-cols-2">
                    @foreach ($group['flags'] as $flag)
                        <li wire:key="flag-{{ $flag['key'] }}">
                            <x-admin-flag-row :flag="$flag" mode="platform">
                                <span class="flex shrink-0 items-center gap-2">
                                    @if ($flag['overridden'])
                                        <span
                                            class="inline-flex items-center gap-1 rounded-full bg-brand-rust/10 px-2 py-0.5 text-[10px] font-semibold text-brand-rust"
                                            title="{{ __('Platform override — differs from config default') }}"
                                        >
                                            {{ __('override') }}
                                            <button
                                                type="button"
                                                wire:click.prevent="resetPlatformFlag('{{ $flag['key'] }}')"
                                                wire:loading.attr="disabled"
                                                class="underline-offset-2 hover:underline"
                                            >{{ __('reset') }}</button>
                                        </span>
                                    @endif

                                    @if ($flag['orgOverrides'] > 0)
                                        <button
                                            type="button"
                                            wire:click.prevent="requestClearOrgOverrides('{{ $flag['key'] }}')"
                                            wire:loading.attr="disabled"
                                            class="inline-flex items-center rounded-full bg-brand-ink/5 px-2 py-0.5 text-[10px] font-medium text-brand-moss hover:bg-brand-ink/10"
                                            title="{{ __('Clear per-org overrides') }}"
                                        >{{ __(':count org', ['count' => $flag['orgOverrides']]) }}</button>
                                    @endif

                                    <input
                                        type="checkbox"
                                        wire:click="togglePlatformFlag('{{ $flag['key'] }}')"
                                        wire:loading.attr="disabled"
                                        @checked($flag['active'])
                                        class="h-4 w-4 shrink-0 rounded border-brand-ink/30 text-brand-sage focus:ring-brand-sage"
                                    />
                                </span>
                            </x-admin-flag-row>
                        </li>
                    @endforeach
                </ul>
            </section>
        @empty
            <div class="dply-card-compact text-center text-sm text-brand-moss">
                {{ __('No flags match your filters.') }}
            </div>
        @endforelse
    </div>

    @include('livewire.partials.confirm-action-modal')
</div>
