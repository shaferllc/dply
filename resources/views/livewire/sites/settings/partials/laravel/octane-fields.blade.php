@props(['site'])

@if ($site->shouldShowPhpOctaneRolloutSettings() && $site->shouldShowOctaneRuntimeUi())
    <div class="space-y-2">
        <p class="text-xs leading-relaxed text-brand-moss">{{ __('`laravel/octane` detected. Set the Octane port to match your reverse proxy. Workers (Supervisor) preset should use the same `--port` and `--server`.') }}</p>
        <div class="grid grid-cols-1 gap-2.5 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <x-input-label for="laravel_workspace_octane_port" :value="__('Octane port')" class="!text-xs" />
                <x-text-input id="laravel_workspace_octane_port" wire:model="octane_port" class="mt-1 block w-full font-mono text-sm" placeholder="8000" />
                <x-input-error :messages="$errors->get('octane_port')" class="mt-1" />
            </div>
            <div>
                <x-input-label for="laravel_workspace_octane_server" :value="__('Octane application server')" class="!text-xs" />
                <select id="laravel_workspace_octane_server" wire:model="octane_server" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm text-sm">
                    @foreach (\App\Models\Site::OCTANE_SERVERS as $octaneServer)
                        <option value="{{ $octaneServer }}">{{ str($octaneServer)->replace('_', ' ')->title() }}</option>
                    @endforeach
                </select>
                <p class="mt-1 font-mono text-xs text-brand-moss">{{ $site->octaneSupervisorCommand() }}</p>
                <x-input-error :messages="$errors->get('octane_server')" class="mt-1" />
            </div>
        </div>
    </div>
@endif
