@php
    $tonePalette = [
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
    ];
@endphp

<x-server-workspace-layout
    :server="$server"
    active="maintenance"
    :title="__('Maintenance')"
    :description="__('Suspend every eligible site on this server with one toggle and a shared visitor message — ideal for patch windows or provider maintenance.')"
>
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <x-explainer>
        <p>{{ __('Eligible VM sites with managed webserver config are suspended and serve the standard Dply suspended page. Deploy hooks and settings still work. Sites you suspended manually before enabling are left unchanged and are not auto-resumed when maintenance ends.') }}</p>
    </x-explainer>

    <div class="space-y-6">
        @if ($active)
            <section class="dply-card overflow-hidden border-amber-200">
                <div class="border-b border-amber-200/80 bg-amber-50/70 px-6 py-5 sm:px-7">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="flex items-start gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $tonePalette['amber'] }}">
                                <x-heroicon-o-wrench class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <div>
                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Maintenance active') }}</p>
                                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Visitors see suspended pages on this server') }}</h2>
                                <p class="mt-1 text-sm text-amber-900/90">
                                    @if (! empty($state['until']))
                                        {{ __('Scheduled until :time.', ['time' => \Illuminate\Support\Carbon::parse($state['until'])->timezone(config('app.timezone'))->format('Y-m-d H:i T')]) }}
                                    @else
                                        {{ __('No automatic end time — clear manually when work finishes.') }}
                                    @endif
                                    @if (! empty($state['note']))
                                        {{ $state['note'] }}
                                    @endif
                                </p>
                            </div>
                        </div>
                        <button
                            type="button"
                            wire:click="openDisableModal"
                            class="inline-flex items-center rounded-lg border border-amber-300 bg-white px-3 py-1.5 text-xs font-semibold text-amber-900 hover:bg-amber-100"
                        >
                            {{ __('End maintenance') }}
                        </button>
                    </div>
                </div>
                <div class="px-6 py-4 text-sm text-brand-moss sm:px-7">
                    {{ trans_choice(':count site suspended by this window|:count sites suspended by this window', count($state['suspended_site_ids'] ?? []), ['count' => count($state['suspended_site_ids'] ?? [])]) }}
                    @if (! empty($state['message']))
                        · {{ __('Public message') }}: <span class="font-medium text-brand-ink">{{ $state['message'] }}</span>
                    @endif
                </div>
            </section>
        @endif

        <section class="dply-card overflow-hidden">
            <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-7">
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $tonePalette['sage'] }}">
                        <x-heroicon-o-pause-circle class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div>
                        <h2 class="text-base font-semibold text-brand-ink">{{ $active ? __('Maintenance settings') : __('Start maintenance window') }}</h2>
                        <p class="mt-1 text-sm text-brand-moss">
                            {{ __(':eligible eligible sites on this server — :ready would be suspended now.', [
                                'eligible' => $eligibleCount,
                                'ready' => $preview['suspend_count'],
                            ]) }}
                            @if ($preview['already_suspended'] > 0)
                                {{ trans_choice(':count is already suspended individually.|:count are already suspended individually.', $preview['already_suspended'], ['count' => $preview['already_suspended']]) }}
                            @endif
                        </p>
                    </div>
                </div>
            </div>

            <form class="space-y-5 p-6 sm:p-7">
                <div>
                    <x-input-label for="maintenance_until_local" :value="__('End automatically at (optional)')" />
                    <input
                        id="maintenance_until_local"
                        type="datetime-local"
                        wire:model="maintenance_until_local"
                        @disabled($active)
                        class="mt-1 block w-full max-w-md rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30 disabled:bg-brand-sand/30"
                    />
                    <p class="mt-1.5 text-xs text-brand-moss">{{ __('Times use :tz. Leave empty for a manual clear-only window.', ['tz' => config('app.timezone')]) }}</p>
                    <x-input-error :messages="$errors->get('maintenance_until_local')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="maintenance_note" :value="__('Operator note (internal)')" />
                    <textarea
                        id="maintenance_note"
                        wire:model="maintenance_note"
                        rows="2"
                        maxlength="500"
                        @disabled($active)
                        class="mt-1 block w-full max-w-2xl rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-2 focus:ring-brand-sage/30 disabled:bg-brand-sand/30"
                        placeholder="{{ __('e.g. kernel patch + nginx reload — ETA 30 minutes') }}"
                    ></textarea>
                    <x-input-error :messages="$errors->get('maintenance_note')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="maintenance_message" :value="__('Public visitor message (optional)')" />
                    <textarea
                        id="maintenance_message"
                        wire:model="maintenance_message"
                        rows="2"
                        maxlength="500"
                        @disabled($active)
                        class="mt-1 block w-full max-w-2xl rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-2 focus:ring-brand-sage/30 disabled:bg-brand-sand/30"
                        placeholder="{{ __('Shown on each site\'s suspended page — e.g. Scheduled maintenance until 18:00 UTC.') }}"
                    ></textarea>
                    <x-input-error :messages="$errors->get('maintenance_message')" class="mt-1" />
                </div>

                @if (! $active)
                    <x-primary-button type="button" wire:click="openEnableModal">
                        {{ __('Review and enable maintenance') }}
                    </x-primary-button>
                @endif
            </form>
        </section>
    </div>

    <x-modal name="enable-maintenance-confirmation" maxWidth="md">
        <div class="p-6">
            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Enable server maintenance?') }}</h2>
            <p class="mt-2 text-sm text-brand-moss">
                {{ trans_choice('This will suspend :count eligible site on this server and queue webserver config updates.|This will suspend :count eligible sites on this server and queue webserver config updates.', $preview['suspend_count'], ['count' => $preview['suspend_count']]) }}
                {{ __('Visitors will see the suspended page until you end maintenance.') }}
            </p>
            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button type="button" wire:click="closeEnableModal">{{ __('Cancel') }}</x-secondary-button>
                <x-danger-button type="button" wire:click="enableMaintenance" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="enableMaintenance">{{ __('Enable maintenance') }}</span>
                    <span wire:loading wire:target="enableMaintenance">{{ __('Enabling…') }}</span>
                </x-danger-button>
            </div>
        </div>
    </x-modal>

    <x-modal name="disable-maintenance-confirmation" maxWidth="md">
        <div class="p-6">
            <h2 class="text-lg font-semibold text-brand-ink">{{ __('End server maintenance?') }}</h2>
            <p class="mt-2 text-sm text-brand-moss">
                {{ __('Sites suspended by this maintenance window will be resumed and webserver configs re-applied. Manually suspended sites are unchanged.') }}
            </p>
            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button type="button" wire:click="closeDisableModal">{{ __('Cancel') }}</x-secondary-button>
                <x-primary-button type="button" wire:click="disableMaintenance" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="disableMaintenance">{{ __('End maintenance') }}</span>
                    <span wire:loading wire:target="disableMaintenance">{{ __('Ending…') }}</span>
                </x-primary-button>
            </div>
        </div>
    </x-modal>
</x-server-workspace-layout>
