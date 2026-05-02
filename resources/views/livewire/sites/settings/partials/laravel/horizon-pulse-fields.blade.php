@props(['site', 'server'])

@php
    $daemonsUrl = route('sites.daemons', ['server' => $server, 'site' => $site]);
@endphp

<div class="space-y-6">
    @if (in_array('horizon', $site->detectedLaravelPackageKeys(), true))
        <div class="rounded-xl border border-brand-ink/10 bg-white p-4 sm:col-span-2">
            <p class="text-sm font-semibold text-brand-ink">{{ __('Horizon') }}</p>
            <p class="mt-2 text-xs text-brand-moss leading-relaxed">{{ __('Queue dashboard. Run Horizon under Supervisor (preset available).') }}</p>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <x-input-label for="laravel_setup_horizon_path" :value="__('Dashboard path')" />
                    <x-text-input
                        id="laravel_setup_horizon_path"
                        wire:model="laravel_horizon_path"
                        class="mt-1 block w-full font-mono text-sm"
                        placeholder="/horizon"
                    />
                    <x-input-error :messages="$errors->get('laravel_horizon_path')" class="mt-1" />
                </div>
                <div class="sm:col-span-2">
                    <x-input-label for="laravel_setup_horizon_notes" :value="__('Notes (optional)')" />
                    <textarea
                        id="laravel_setup_horizon_notes"
                        wire:model="laravel_horizon_notes"
                        rows="2"
                        class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm"
                        placeholder="{{ __('e.g. production only, IP allowlist') }}"
                    ></textarea>
                    <x-input-error :messages="$errors->get('laravel_horizon_notes')" class="mt-1" />
                </div>
            </div>
            <a href="{{ $daemonsUrl }}?preset=laravel-horizon" wire:navigate class="mt-3 inline-flex text-xs font-medium text-brand-forest underline">{{ __('Open Daemons with Horizon preset') }}</a>
        </div>
    @endif

    @if (in_array('pulse', $site->detectedLaravelPackageKeys(), true))
        <div class="rounded-xl border border-brand-ink/10 bg-white p-4 sm:col-span-2">
            <p class="text-sm font-semibold text-brand-ink">{{ __('Pulse') }}</p>
            <p class="mt-2 text-xs text-brand-moss leading-relaxed">{{ __('Metrics UI. Run `php artisan pulse:check` via cron or worker as Laravel docs describe.') }}</p>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <x-input-label for="laravel_setup_pulse_path" :value="__('Dashboard path')" />
                    <x-text-input
                        id="laravel_setup_pulse_path"
                        wire:model="laravel_pulse_path"
                        class="mt-1 block w-full font-mono text-sm"
                        placeholder="/pulse"
                    />
                    <x-input-error :messages="$errors->get('laravel_pulse_path')" class="mt-1" />
                </div>
                <div class="sm:col-span-2">
                    <x-input-label for="laravel_setup_pulse_notes" :value="__('Notes (optional)')" />
                    <textarea
                        id="laravel_setup_pulse_notes"
                        wire:model="laravel_pulse_notes"
                        rows="2"
                        class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm"
                        placeholder="{{ __('e.g. internal only') }}"
                    ></textarea>
                    <x-input-error :messages="$errors->get('laravel_pulse_notes')" class="mt-1" />
                </div>
            </div>
        </div>
    @endif
</div>
