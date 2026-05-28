<div>
    <x-page-header
        :title="__('App-wide feature flags')"
        :description="__('Global kill switches and cross-cutting product flags. Changes require confirmation because they affect every organization.')"
        flush
        compact
    />

    <div class="space-y-6">
        @foreach ($groups as $group)
            <section class="dply-card-compact">
                <h2 class="text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ $group['title'] }}</h2>
                <ul class="mt-3 grid gap-2 lg:grid-cols-2">
                    @foreach ($group['flags'] as $flag)
                        <li wire:key="global-flag-{{ $flag['key'] }}">
                            <x-admin-flag-row :flag="$flag" mode="global">
                                <button
                                    type="button"
                                    wire:click="requestGlobalFeatureFlagToggle('{{ $flag['key'] }}')"
                                    role="switch"
                                    aria-checked="{{ $flag['active'] ? 'true' : 'false' }}"
                                    @class([
                                        'relative inline-flex h-6 w-11 shrink-0 rounded-full border-2 border-transparent transition-colors focus:outline-none focus:ring-2 focus:ring-brand-sage focus:ring-offset-2',
                                        'bg-brand-sage' => $flag['active'],
                                        'bg-brand-ink/20' => ! $flag['active'],
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
        @endforeach
    </div>

    @include('livewire.partials.confirm-action-modal')
</div>
