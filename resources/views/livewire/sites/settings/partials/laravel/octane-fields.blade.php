@props(['site'])

@if ($site->shouldShowPhpOctaneRolloutSettings() && $site->shouldShowOctaneRuntimeUi())
    <div class="space-y-3">
        @if ($site->shouldShowOctaneRuntimeUi())
            <p class="text-sm text-brand-moss">{{ __('Inspection found `laravel/octane` in composer.json. Set the Octane port to match your reverse proxy (managed configs proxy to 127.0.0.1). Use the Supervisor preset on the server’s Daemons tab—align `--port` and `--server` with the values below.') }}</p>
        @endif
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <x-input-label for="laravel_workspace_octane_port" :value="__('Octane port')" />
                <x-text-input id="laravel_workspace_octane_port" wire:model="octane_port" class="mt-1 block w-full font-mono text-sm" placeholder="8000" />
                <x-input-error :messages="$errors->get('octane_port')" class="mt-1" />
            </div>
            <div>
                <x-input-label for="laravel_workspace_octane_server" :value="__('Octane application server')" />
                <select id="laravel_workspace_octane_server" wire:model="octane_server" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm text-sm">
                    @foreach (\App\Models\Site::OCTANE_SERVERS as $octaneServer)
                        <option value="{{ $octaneServer }}">{{ str($octaneServer)->replace('_', ' ')->title() }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-brand-moss font-mono">{{ $site->octaneSupervisorCommand() }}</p>
                <x-input-error :messages="$errors->get('octane_server')" class="mt-1" />
            </div>
        </div>
    </div>
@endif
