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
            <p class="mb-3 text-sm text-brand-moss">{{ __('Kill switches are set in config/features.php (via env) and shown here read-only.') }}</p>
            <ul class="grid gap-2 lg:grid-cols-2">
                @foreach ($emergencyFlags as $flag)
                    <li wire:key="emergency-{{ $flag['key'] }}">
                        <x-admin-flag-row :flag="$flag" mode="global">
                            <x-admin-flag-state :active="$flag['active']" />
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
                    <p class="mt-1 text-xs text-brand-moss">{{ __('App-wide flag set in config — not overridable per org.') }}</p>
                @else
                    <p class="mt-1 text-xs text-brand-moss">{{ __('Config default for every org unless an org has an explicit override. Set the global default in config/features.php; override per org from the organization page. Coming soon previews are nested under their feature.') }}</p>
                @endif
                <ul class="mt-3 grid gap-2 lg:grid-cols-2">
                    @foreach ($group['flags'] as $flag)
                        <li wire:key="flag-{{ $flag['key'] }}">
                            @if (! empty($flag['preview']))
                                <div class="overflow-hidden rounded-lg border border-brand-ink/10 bg-white shadow-sm">
                                    <div class="border-b border-brand-ink/8 px-3 py-2.5">
                                        <x-admin-flag-row :flag="$flag" :mode="$group['mode'] === 'global' ? 'global' : 'platform'">
                                            @if ($group['mode'] === 'global')
                                                <x-admin-flag-state :active="$flag['active']" />
                                            @else
                                                <div class="flex shrink-0 flex-col items-end gap-2">
                                                    <x-admin-flag-state :active="$flag['active']" />
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
                                    </div>
                                    <div class="bg-brand-sand/20 px-3 py-2.5">
                                        <x-admin-flag-row :flag="$flag['preview']" mode="platform">
                                            <div class="flex shrink-0 flex-col items-end gap-2">
                                                <x-admin-flag-state :active="$flag['preview']['active']" />
                                                @if (($orgOverrideCounts[$flag['preview']['key']] ?? 0) > 0)
                                                    <button
                                                        type="button"
                                                        wire:click="requestClearOrgOverridesForFlag('{{ $flag['preview']['key'] }}')"
                                                        class="text-[10px] font-semibold text-amber-800 underline decoration-amber-800/40 underline-offset-2 hover:text-amber-900"
                                                    >
                                                        {{ __('Clear :count org override(s)', ['count' => $orgOverrideCounts[$flag['preview']['key']]]) }}
                                                    </button>
                                                @endif
                                            </div>
                                        </x-admin-flag-row>
                                        <p class="mt-1.5 ps-0.5 text-[10px] leading-relaxed text-brand-moss">{{ __('Shows Soon badge + teaser page when the full workspace above is off. Overridable per org.') }}</p>
                                    </div>
                                </div>
                            @else
                                <x-admin-flag-row :flag="$flag" :mode="$group['mode'] === 'global' ? 'global' : 'platform'">
                                    @if ($group['mode'] === 'global')
                                        <x-admin-flag-state :active="$flag['active']" />
                                    @else
                                        <div class="flex shrink-0 flex-col items-end gap-2">
                                            <x-admin-flag-state :active="$flag['active']" />
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
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    </div>

    @include('livewire.partials.confirm-action-modal')
</div>
