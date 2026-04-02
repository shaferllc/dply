@php
    $packages = $site->detectedLaravelPackageKeys();
    $daemonsUrl = route('servers.daemons', $server);
@endphp

<div class="space-y-4 rounded-2xl border border-brand-ink/10 bg-brand-sand/20 p-4 sm:p-5">
    <div>
        <h5 class="text-sm font-semibold text-brand-ink">{{ __('Laravel ecosystem (from composer.json)') }}</h5>
        <p class="mt-1 text-xs text-brand-moss">{{ __('Dply does not install these on the server automatically. Use the hints below with Supervisor on your server and your web server config.') }}</p>
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        @if (in_array('horizon', $packages, true))
            <div class="rounded-xl border border-brand-ink/10 bg-white p-4 sm:col-span-2">
                <p class="text-sm font-semibold text-brand-ink">{{ __('Horizon') }}</p>
                <p class="mt-2 text-xs text-brand-moss leading-relaxed">{{ __('Queue dashboard and workers. Ensure Redis matches your Laravel queue config. Run Horizon under Supervisor (preset available).') }}</p>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div>
                        <x-input-label for="laravel_horizon_path" :value="__('Dashboard path')" />
                        <x-text-input
                            id="laravel_horizon_path"
                            wire:model="laravel_horizon_path"
                            class="mt-1 block w-full font-mono text-sm"
                            placeholder="/horizon"
                        />
                        <p class="mt-1 text-[11px] text-brand-moss">{{ __('Stored for deploy docs and operator reference.') }}</p>
                        <x-input-error :messages="$errors->get('laravel_horizon_path')" class="mt-1" />
                    </div>
                    <div class="sm:col-span-2">
                        <x-input-label for="laravel_horizon_notes" :value="__('Notes (optional)')" />
                        <textarea
                            id="laravel_horizon_notes"
                            wire:model="laravel_horizon_notes"
                            rows="2"
                            class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm"
                            placeholder="{{ __('e.g. production only, IP allowlist') }}"
                        ></textarea>
                        <x-input-error :messages="$errors->get('laravel_horizon_notes')" class="mt-1" />
                    </div>
                </div>
                <a href="{{ $daemonsUrl }}" wire:navigate class="mt-3 inline-flex text-xs font-medium text-brand-forest underline">{{ __('Open server → Daemons') }}</a>
            </div>
        @endif

        @if (in_array('pulse', $packages, true))
            <div class="rounded-xl border border-brand-ink/10 bg-white p-4 sm:col-span-2">
                <p class="text-sm font-semibold text-brand-ink">{{ __('Pulse') }}</p>
                <p class="mt-2 text-xs text-brand-moss leading-relaxed">{{ __('Metrics UI. Run `php artisan pulse:check` via cron or a long-running worker as Laravel docs describe; protect the route in production.') }}</p>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div>
                        <x-input-label for="laravel_pulse_path" :value="__('Dashboard path')" />
                        <x-text-input
                            id="laravel_pulse_path"
                            wire:model="laravel_pulse_path"
                            class="mt-1 block w-full font-mono text-sm"
                            placeholder="/pulse"
                        />
                        <x-input-error :messages="$errors->get('laravel_pulse_path')" class="mt-1" />
                    </div>
                    <div class="sm:col-span-2">
                        <x-input-label for="laravel_pulse_notes" :value="__('Notes (optional)')" />
                        <textarea
                            id="laravel_pulse_notes"
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

        @if (in_array('reverb', $packages, true) && $site->shouldShowPhpOctaneRolloutSettings())
            <div class="rounded-xl border border-brand-ink/10 bg-white p-4 sm:col-span-2">
                <p class="text-sm font-semibold text-brand-ink">{{ __('Reverb') }}</p>
                <p class="mt-2 text-xs text-brand-moss leading-relaxed">{{ __('WebSocket server. Set the port to match Supervisor (`php artisan reverb:start`). Managed Nginx, Caddy, Apache, and OpenLiteSpeed configs include a WebSocket proxy for this path when a port is saved.') }}</p>
                <div class="mt-3 grid gap-4 sm:grid-cols-2 max-w-2xl">
                    <div>
                        <x-input-label for="laravel_stack_laravel_reverb_port" :value="__('Reverb listen port (localhost)')" />
                        <x-text-input
                            id="laravel_stack_laravel_reverb_port"
                            type="number"
                            wire:model="laravel_reverb_port"
                            class="mt-1 block w-full font-mono text-sm"
                            placeholder="8080"
                            min="1"
                            max="65535"
                        />
                        <p class="mt-1 text-[11px] text-brand-moss font-mono">{{ $site->reverbSupervisorCommandLine(($laravel_reverb_port ?? '') !== '' ? (int) $laravel_reverb_port : null) }}</p>
                        <x-input-error :messages="$errors->get('laravel_reverb_port')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="laravel_reverb_ws_path" :value="__('WebSocket URL path (Echo / Reverb)')" />
                        <x-text-input
                            id="laravel_reverb_ws_path"
                            wire:model="laravel_reverb_ws_path"
                            class="mt-1 block w-full font-mono text-sm"
                            placeholder="/app"
                        />
                        <p class="mt-1 text-[11px] text-brand-moss">{{ __('Default `/app`; must match `broadcasting` / Echo config.') }}</p>
                        <x-input-error :messages="$errors->get('laravel_reverb_ws_path')" class="mt-1" />
                    </div>
                </div>
                <a href="{{ $daemonsUrl }}" wire:navigate class="mt-3 inline-flex text-xs font-medium text-brand-forest underline">{{ __('Open server → Daemons') }}</a>
            </div>
        @endif
    </div>
</div>
