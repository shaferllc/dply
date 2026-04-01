<div>
    <header class="border-b border-slate-200 bg-white">
        <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <a href="{{ route('status-pages.index') }}" class="text-sm text-slate-600 hover:text-slate-900">{{ __('← Status pages') }}</a>
                    <h2 class="font-semibold text-xl text-slate-800 leading-tight mt-2">{{ $statusPage->name }}</h2>
                </div>
                @can('delete', $statusPage)
                    <button
                        type="button"
                        wire:click="openConfirmActionModal('destroyPage', [], @js(__('Delete status page')), @js(__('Delete this status page? Monitors and incidents are removed.')), @js(__('Delete')), true)"
                        class="text-sm text-red-600 hover:text-red-800"
                    >
                        {{ __('Delete') }}
                    </button>
                @endcan
            </div>
        </div>
    </header>
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-8">
            @if (session('success'))
                <div class="p-4 rounded-md bg-green-50 text-green-800 text-sm">{{ session('success') }}</div>
            @endif

            <div class="bg-white border border-slate-200 rounded-lg p-6">
                <h3 class="font-medium text-slate-900 mb-4">{{ __('Page details') }}</h3>
                <form wire:submit="saveDetails" class="space-y-4 max-w-xl">
                    <div>
                        <x-input-label for="edit-name" :value="__('Name')" />
                        <x-text-input id="edit-name" wire:model="editName" type="text" class="mt-1 block w-full" required />
                        <x-input-error :messages="$errors->get('editName')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="edit-desc" :value="__('Description')" />
                        <textarea id="edit-desc" wire:model="editDescription" rows="2" class="mt-1 block w-full border-slate-300 rounded-md shadow-sm text-sm"></textarea>
                    </div>
                    <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" wire:model="is_public" class="rounded border-slate-300 text-slate-900" />
                        {{ __('Public status page (anyone with the link can view)') }}
                    </label>
                    <div>
                        <x-primary-button type="submit">{{ __('Save') }}</x-primary-button>
                    </div>
                </form>
                <p class="mt-4 text-xs text-slate-500">
                    {{ __('Public URL:') }}
                    <a href="{{ route('status.public', $statusPage) }}" target="_blank" class="text-slate-700 underline">{{ url('/status/'.$statusPage->slug) }}</a>
                </p>
            </div>

            <div class="bg-white border border-slate-200 rounded-lg p-6">
                <h3 class="font-medium text-slate-900 mb-2">{{ __('Monitors') }}</h3>
                <p class="text-sm text-slate-600 mb-4">{{ __('Server status uses health checks from the server list (SSH port or optional HTTP URL). Sites follow the parent server and site state.') }}</p>

                @if ($statusPage->monitors->isEmpty())
                    <p class="text-sm text-slate-500 mb-4">{{ __('No monitors yet.') }}</p>
                @else
                    <ul class="divide-y divide-slate-100 mb-6">
                        @foreach ($statusPage->monitors as $mon)
                            <li class="py-3 flex items-center justify-between gap-3">
                                <div>
                                    <span class="font-medium text-slate-900">{{ $mon->displayLabel() }}</span>
                                    <span class="text-xs text-slate-500 ml-2">{{ class_basename($mon->monitorable_type) }}</span>
                                </div>
                                <button type="button" wire:click="removeMonitor('{{ $mon->id }}')" class="text-xs text-red-600 hover:underline">{{ __('Remove') }}</button>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <div class="flex flex-wrap gap-4 items-end">
                    <div>
                        <x-input-label for="mk" :value="__('Type')" />
                        <select id="mk" wire:model.live="monitorKind" class="mt-1 block w-full border-slate-300 rounded-md shadow-sm text-sm">
                            <option value="server">{{ __('Server') }}</option>
                            <option value="site">{{ __('Site') }}</option>
                        </select>
                    </div>
                    <div class="min-w-[12rem]">
                        <x-input-label for="mid" :value="__('Resource')" />
                        <select id="mid" wire:model="monitorId" class="mt-1 block w-full border-slate-300 rounded-md shadow-sm text-sm">
                            <option value="">{{ __('Choose…') }}</option>
                            @if ($monitorKind === 'server')
                                @foreach ($servers as $s)
                                    <option value="{{ $s->id }}">{{ $s->name }}</option>
                                @endforeach
                            @else
                                @foreach ($sites as $s)
                                    <option value="{{ $s->id }}">{{ $s->name }}</option>
                                @endforeach
                            @endif
                        </select>
                        <x-input-error :messages="$errors->get('monitorId')" class="mt-1" />
                    </div>
                    <div class="min-w-[10rem]">
                        <x-input-label for="ml" :value="__('Label (optional)')" />
                        <x-text-input id="ml" wire:model="monitorLabel" type="text" class="mt-1 block w-full text-sm" placeholder="{{ __('Override display name') }}" />
                    </div>
                    <x-secondary-button type="button" wire:click="addMonitor">{{ __('Add monitor') }}</x-secondary-button>
                </div>
            </div>

            <div class="bg-white border border-slate-200 rounded-lg p-6">
                <h3 class="font-medium text-slate-900 mb-4">{{ __('Incidents') }}</h3>

                <form wire:submit="createIncident" class="space-y-3 max-w-2xl mb-10 border-b border-slate-100 pb-8">
                    <div>
                        <x-input-label for="it" :value="__('Title')" />
                        <x-text-input id="it" wire:model="incidentTitle" type="text" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('incidentTitle')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="ii" :value="__('Impact')" />
                        <select id="ii" wire:model="incidentImpact" class="mt-1 block w-full border-slate-300 rounded-md shadow-sm text-sm">
                            <option value="none">{{ __('None') }}</option>
                            <option value="minor">{{ __('Minor') }}</option>
                            <option value="major">{{ __('Major') }}</option>
                            <option value="critical">{{ __('Critical') }}</option>
                        </select>
                    </div>
                    <div>
                        <x-input-label for="im" :value="__('First update')" />
                        <textarea id="im" wire:model="incidentMessage" rows="4" class="mt-1 block w-full border-slate-300 rounded-md shadow-sm text-sm"></textarea>
                        <x-input-error :messages="$errors->get('incidentMessage')" class="mt-1" />
                    </div>
                    <x-primary-button type="submit">{{ __('Open incident') }}</x-primary-button>
                </form>

                @foreach ($statusPage->incidents->sortByDesc('started_at') as $incident)
                    <div class="mb-8 border border-slate-100 rounded-lg p-4 bg-slate-50/50">
                        <div class="flex flex-wrap items-start justify-between gap-2 mb-2">
                            <div>
                                <h4 class="font-semibold text-slate-900">{{ $incident->title }}</h4>
                                <p class="text-xs text-slate-500 mt-1">
                                    {{ $incident->started_at->toDayDateTimeString() }}
                                    · {{ ucfirst($incident->impact) }} impact
                                    · {{ str_replace('_', ' ', $incident->state) }}
                                    @if ($incident->resolved_at)
                                        · {{ __('Resolved :time', ['time' => $incident->resolved_at->diffForHumans()]) }}
                                    @endif
                                </p>
                            </div>
                            <div class="flex flex-wrap gap-1">
                                <button type="button" wire:click="setIncidentState('{{ $incident->id }}', '{{ App\Models\Incident::STATE_INVESTIGATING }}')" class="text-xs px-2 py-1 rounded bg-white border border-slate-200">{{ __('Investigating') }}</button>
                                <button type="button" wire:click="setIncidentState('{{ $incident->id }}', '{{ App\Models\Incident::STATE_IDENTIFIED }}')" class="text-xs px-2 py-1 rounded bg-white border border-slate-200">{{ __('Identified') }}</button>
                                <button type="button" wire:click="setIncidentState('{{ $incident->id }}', '{{ App\Models\Incident::STATE_MONITORING }}')" class="text-xs px-2 py-1 rounded bg-white border border-slate-200">{{ __('Monitoring') }}</button>
                                <button type="button" wire:click="setIncidentState('{{ $incident->id }}', '{{ App\Models\Incident::STATE_RESOLVED }}')" class="text-xs px-2 py-1 rounded bg-green-50 border border-green-200 text-green-800">{{ __('Resolved') }}</button>
                            </div>
                        </div>
                        <ul class="space-y-3 text-sm text-slate-700 mb-4">
                            @foreach ($incident->incidentUpdates as $u)
                                <li class="border-l-2 border-slate-200 pl-3">
                                    <span class="text-xs text-slate-400">{{ $u->created_at->toDayDateTimeString() }}</span>
                                    <p class="whitespace-pre-wrap">{{ $u->body }}</p>
                                </li>
                            @endforeach
                        </ul>
                        <div class="flex flex-wrap gap-2 items-end">
                            <div class="flex-1 min-w-[12rem]">
                                <x-input-label :value="__('Add update')" />
                                <textarea wire:model="updateBodies.{{ $incident->id }}" rows="2" class="mt-1 block w-full border-slate-300 rounded-md shadow-sm text-sm" placeholder="{{ __('Status update…') }}"></textarea>
                            </div>
                            <x-secondary-button type="button" wire:click="addIncidentUpdate('{{ $incident->id }}')">{{ __('Post') }}</x-secondary-button>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
    </x-slot>
</div>
