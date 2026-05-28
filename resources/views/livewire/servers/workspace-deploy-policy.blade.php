@php
    $tonePalette = ['amber' => 'bg-amber-50 text-amber-900 ring-amber-200', 'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-200'];
@endphp

<x-server-workspace-layout :server="$server" active="deploy-policy" :title="__('Deploy windows')" :description="__('Block deploys outside allowed hours — e.g. no production releases Friday evening through Monday morning.')">
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])
    <x-explainer><p>{{ __('When enabled, deploy jobs for every site on this server are skipped with a clear log message during deny windows. Concurrent deploys per site remain limited to one via the existing deploy lock.') }}</p></x-explainer>

    <div class="space-y-6">
        <section class="dply-card overflow-hidden">
            <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-7">
                <div class="flex items-start gap-3">
                    <span @class(['flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1', $currentAllowed ? $tonePalette['emerald'] : $tonePalette['amber']])><x-heroicon-o-calendar-days class="h-5 w-5" /></span>
                    <div>
                        <h2 class="text-base font-semibold text-brand-ink">{{ $currentAllowed ? __('Deploys allowed now') : __('Deploys blocked now') }}</h2>
                        @if (! $currentAllowed && $blockReason)<p class="mt-1 text-sm text-amber-900">{{ $blockReason }}</p>@endif
                    </div>
                </div>
            </div>
        </section>

        <section class="dply-card overflow-hidden">
            <form wire:submit="savePolicy" class="space-y-5 p-6 sm:p-7">
                <label class="inline-flex items-center gap-2 text-sm font-medium text-brand-ink">
                    <input type="checkbox" wire:model.live="policy_enabled" class="rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage/40">
                    {{ __('Enable deploy window policy') }}
                </label>

                <div>
                    <x-input-label for="policy_timezone" :value="__('Timezone')" />
                    <x-text-input id="policy_timezone" wire:model="policy_timezone" class="mt-1 block w-full max-w-xs" />
                    <x-input-error :messages="$errors->get('policy_timezone')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="policy_message" :value="__('Skip message')" />
                    <textarea id="policy_message" wire:model="policy_message" rows="2" class="mt-1 block w-full rounded-lg border border-brand-ink/15 px-3 py-2 text-sm"></textarea>
                </div>

                <div class="space-y-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="text-sm font-semibold text-brand-ink">{{ __('Deny windows') }}</h3>
                        <div class="flex gap-2">
                            <button type="button" wire:click="applyWeekendFreezePreset" class="rounded-lg border border-brand-ink/15 px-2.5 py-1 text-xs font-semibold">{{ __('Weekend freeze preset') }}</button>
                            <button type="button" wire:click="addDenyRule" class="rounded-lg border border-brand-ink/15 px-2.5 py-1 text-xs font-semibold">{{ __('Add rule') }}</button>
                        </div>
                    </div>
                    @foreach ($deny_rules as $index => $rule)
                        <div class="rounded-xl border border-brand-ink/10 p-4" wire:key="deny-rule-{{ $index }}">
                            <div class="flex flex-wrap gap-3">
                                <div class="min-w-[12rem] flex-1">
                                    <p class="text-xs font-medium text-brand-moss">{{ __('Days') }}</p>
                                    <div class="mt-1 flex flex-wrap gap-2">
                                        @foreach ($dayOptions as $day)
                                            <label class="inline-flex items-center gap-1 text-xs">
                                                <input type="checkbox" value="{{ $day }}" wire:model="deny_rules.{{ $index }}.days"> {{ strtoupper($day) }}
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                                <div><p class="text-xs font-medium text-brand-moss">{{ __('Start') }}</p><input type="time" wire:model="deny_rules.{{ $index }}.start" class="mt-1 rounded border border-brand-ink/15 px-2 py-1 text-sm"></div>
                                <div><p class="text-xs font-medium text-brand-moss">{{ __('End') }}</p><input type="time" wire:model="deny_rules.{{ $index }}.end" class="mt-1 rounded border border-brand-ink/15 px-2 py-1 text-sm"></div>
                                <button type="button" wire:click="removeDenyRule({{ $index }})" class="self-end text-xs font-semibold text-rose-700">{{ __('Remove') }}</button>
                            </div>
                        </div>
                    @endforeach
                </div>

                <x-primary-button type="submit">{{ __('Save policy') }}</x-primary-button>
            </form>
        </section>
    </div>
</x-server-workspace-layout>
