<div>
    <x-page-header
        :title="$lineTitle"
        :description="$lineDescription"
        flush
        compact
    />

    @if ($emergencyFlags !== [])
        <section class="mb-8" aria-labelledby="emergency-flags-heading">
            <h2 id="emergency-flags-heading" class="mb-3 text-xs font-semibold uppercase tracking-[0.14em] text-red-800">
                {{ __('Emergency controls') }}
            </h2>
            <p class="mb-3 text-sm text-brand-moss">{{ __('Kill switches win over platform defaults and org overrides. Changes require confirmation.') }}</p>
            <ul class="grid gap-2 lg:grid-cols-2">
                @foreach ($emergencyFlags as $flag)
                    <li wire:key="emergency-{{ $flag['key'] }}">
                        <x-admin-flag-row :flag="$flag" mode="global">
                            <button
                                type="button"
                                wire:click="requestLineEmergencyToggle('{{ $flag['key'] }}')"
                                role="switch"
                                aria-checked="{{ $flag['active'] ? 'true' : 'false' }}"
                                @class([
                                    'relative inline-flex h-6 w-11 shrink-0 rounded-full border-2 border-transparent transition-colors focus:outline-none focus:ring-2 focus:ring-red-400 focus:ring-offset-2',
                                    'bg-brand-sage' => $flag['active'],
                                    'bg-red-300' => ! $flag['active'],
                                ])
                            >
                                <span @class([
                                    'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition',
                                    'translate-x-5' => $flag['active'],
                                    'translate-x-0' => ! $flag['active'],
                                ])></span>
                            </button>
                        </x-admin-flag-row>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <div class="space-y-6">
        @foreach ($groups as $group)
            <section class="dply-card-compact" wire:key="group-{{ $group['title'] }}">
                <h2 class="text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ $group['title'] }}</h2>
                @if ($group['mode'] === 'global')
                    <p class="mt-1 text-xs text-brand-moss">{{ __('App-wide flag — not overridable per org.') }}</p>
                @elseif ($group['mode'] === 'platform_teaser')
                    <p class="mt-1 text-xs text-brand-moss">{{ __('Platform-wide coming-soon teaser — applies to every org when browser console is off. Not overridable per org.') }}</p>
                @else
                    <p class="mt-1 text-xs text-brand-moss">{{ __('Platform default for all orgs unless an org has an explicit override on its admin detail page.') }}</p>
                @endif
                <ul class="mt-3 grid gap-2 lg:grid-cols-2">
                    @foreach ($group['flags'] as $flag)
                        <li wire:key="flag-{{ $flag['key'] }}">
                            <x-admin-flag-row :flag="$flag" :mode="$group['mode'] === 'global' ? 'global' : 'platform'">
                                @if ($group['mode'] === 'global')
                                    <button
                                        type="button"
                                        wire:click="requestProductLineGlobalToggle('{{ $flag['key'] }}')"
                                        role="switch"
                                        aria-checked="{{ $flag['active'] ? 'true' : 'false' }}"
                                        @class(['relative inline-flex h-6 w-11 shrink-0 rounded-full border-2 border-transparent transition-colors focus:outline-none focus:ring-2 focus:ring-brand-sage focus:ring-offset-2', 'bg-brand-sage' => $flag['active'], 'bg-brand-ink/20' => ! $flag['active']])
                                    >
                                        <span @class(['pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition', 'translate-x-5' => $flag['active'], 'translate-x-0' => ! $flag['active']])></span>
                                    </button>
                                @else
                                    <div class="flex shrink-0 flex-col items-end gap-2">
                                        <button
                                            type="button"
                                            wire:click="requestPlatformDefaultFeatureFlagToggle('{{ $flag['key'] }}')"
                                            role="switch"
                                            aria-checked="{{ $flag['active'] ? 'true' : 'false' }}"
                                            @class(['relative inline-flex h-6 w-11 shrink-0 rounded-full border-2 border-transparent transition-colors focus:outline-none focus:ring-2 focus:ring-brand-sage focus:ring-offset-2', 'bg-brand-sage' => $flag['active'], 'bg-brand-ink/20' => ! $flag['active']])
                                        >
                                            <span @class(['pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition', 'translate-x-5' => $flag['active'], 'translate-x-0' => ! $flag['active']])></span>
                                        </button>
                                        @if (($orgOverrideCounts[$flag['key']] ?? 0) > 0)
                                            <button
                                                type="button"
                                                wire:click="requestClearOrgOverridesForFlag('{{ $flag['key'] }}')"
                                                class="text-[10px] font-semibold text-amber-800 underline decoration-amber-800/40 underline-offset-2 hover:text-amber-900"
                                            >
                                                {{ __('Clear :count org override(s)', ['count' => $orgOverrideCounts[$flag['key']]]) }}
                                            </button>
                                        @endif
                                    </div>
                                @endif
                            </x-admin-flag-row>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    </div>

    @include('livewire.partials.confirm-action-modal')
</div>
