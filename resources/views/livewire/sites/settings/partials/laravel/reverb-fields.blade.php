@props(['site', 'server'])

@php
    $daemonsUrl = route('sites.daemons', ['server' => $server, 'site' => $site]);
@endphp

@if ($site->shouldShowPhpOctaneRolloutSettings() && ($site->shouldShowLaravelReverbRuntimeUi() || $site->shouldProxyReverbInWebserver()))
    <div class="space-y-4">
        <p class="text-sm text-brand-moss">{{ __('WebSocket server. Match the port to Supervisor (`php artisan reverb:start`). Managed web server configs can proxy the WebSocket path when a port is saved.') }}</p>
        <div class="grid gap-4 sm:grid-cols-2 max-w-2xl">
            <div>
                <x-input-label for="laravel_workspace_reverb_port" :value="__('Reverb listen port (localhost)')" />
                <x-text-input
                    id="laravel_workspace_reverb_port"
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
                <x-input-label for="laravel_workspace_reverb_ws_path" :value="__('WebSocket URL path (Echo / Reverb)')" />
                <x-text-input
                    id="laravel_workspace_reverb_ws_path"
                    wire:model="laravel_reverb_ws_path"
                    class="mt-1 block w-full font-mono text-sm"
                    placeholder="/app"
                />
                <p class="mt-1 text-[11px] text-brand-moss">{{ __('Default `/app`; must match Echo / broadcasting config.') }}</p>
                <x-input-error :messages="$errors->get('laravel_reverb_ws_path')" class="mt-1" />
            </div>
        </div>
        <a href="{{ $daemonsUrl }}" wire:navigate class="inline-flex text-xs font-medium text-brand-forest underline">{{ __('Open Daemons for this site') }}</a>
    </div>
@endif
