<x-server-workspace-layout
    :server="$server"
    active="redis-snapshots"
    :title="__('Snapshots')"
    :description="__('Point-in-time RDB snapshots of the cache engine on this server. Run on demand or on a cron, stored in your S3-style backup destination.')"
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <div class="space-y-6">
        {{-- Run a snapshot now. --}}
        <section class="dply-card overflow-hidden p-6 sm:p-8">
            <h2 class="text-base font-semibold text-brand-ink">{{ __('Run a snapshot now') }}</h2>
            <p class="mt-1 text-sm text-brand-moss">{{ __('BGSAVE on the primary engine, copy the RDB file to your destination, and record the result below.') }}</p>

            @if ($cacheServices->isEmpty())
                <p class="mt-4 rounded-lg border border-amber-200 bg-amber-50/70 px-3 py-2 text-xs text-amber-900">{{ __('No redis-family cache service is installed on this server yet. Install one from the Caches workspace first.') }}</p>
            @elseif ($destinations->isEmpty())
                <p class="mt-4 rounded-lg border border-amber-200 bg-amber-50/70 px-3 py-2 text-xs text-amber-900">
                    {{ __('No S3-style backup destination is configured for this organization. Add one from Settings → Backups, then come back.') }}
                </p>
            @else
                <form wire:submit="runNow" class="mt-4 flex flex-wrap items-end gap-3">
                    <div class="min-w-0 flex-1">
                        <x-input-label for="run_now_destination_id" :value="__('Destination')" />
                        <select
                            id="run_now_destination_id"
                            wire:model="run_now_destination_id"
                            class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm"
                        >
                            <option value="">— {{ __('Select destination') }} —</option>
                            @foreach ($destinations as $dest)
                                <option value="{{ $dest->id }}">{{ $dest->name }} ({{ $dest->provider }})</option>
                            @endforeach
                        </select>
                    </div>
                    <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="runNow">
                        <span wire:loading.remove wire:target="runNow">{{ __('Run snapshot') }}</span>
                        <span wire:loading wire:target="runNow">{{ __('Queueing…') }}</span>
                    </x-primary-button>
                </form>
            @endif
        </section>

        {{-- Schedules. --}}
        <section class="dply-card overflow-hidden">
            <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <h2 class="text-base font-semibold text-brand-ink">{{ __('Schedules') }}</h2>
                <p class="mt-1 text-sm text-brand-moss">{{ __('Recurring snapshots controlled by a cron expression on the control plane.') }}</p>
            </div>
            <div class="p-6 sm:p-7">
                @if ($schedules->isEmpty())
                    <p class="text-sm text-brand-moss">{{ __('No schedules yet.') }}</p>
                @else
                    <ul class="divide-y divide-brand-ink/10">
                        @foreach ($schedules as $schedule)
                            <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-brand-ink">
                                        {{ $schedule->cacheService?->engine ? ucfirst($schedule->cacheService->engine) : '—' }}
                                        <span class="ml-2 inline-flex items-center rounded-full bg-brand-sand/60 px-2 py-0.5 font-mono text-[11px] text-brand-moss">{{ $schedule->cron_expression }}</span>
                                        @if (! $schedule->is_active)
                                            <span class="ml-2 inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-amber-700 ring-1 ring-amber-200">{{ __('Paused') }}</span>
                                        @endif
                                    </p>
                                    <p class="mt-0.5 text-[11px] text-brand-moss">
                                        {{ __('Destination: :name', ['name' => $schedule->backupConfiguration?->name ?? '—']) }}
                                        @if ($schedule->last_run_at)
                                            · {{ __('Last run :time', ['time' => $schedule->last_run_at->diffForHumans()]) }}
                                        @endif
                                    </p>
                                </div>
                                <div class="flex shrink-0 items-center gap-2">
                                    <button
                                        type="button"
                                        wire:click="toggleSchedule('{{ $schedule->id }}')"
                                        class="rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40"
                                    >
                                        {{ $schedule->is_active ? __('Pause') : __('Resume') }}
                                    </button>
                                    <button
                                        type="button"
                                        wire:click="deleteSchedule('{{ $schedule->id }}')"
                                        wire:confirm="{{ __('Delete this schedule?') }}"
                                        class="rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-medium text-red-700 hover:bg-red-100"
                                    >
                                        {{ __('Delete') }}
                                    </button>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($cacheServices->isNotEmpty() && $destinations->isNotEmpty())
                    <form wire:submit="addSchedule" class="mt-6 grid gap-3 sm:grid-cols-3">
                        <div>
                            <x-input-label for="new_cache_service_id" :value="__('Cache service')" />
                            <select id="new_cache_service_id" wire:model="new_cache_service_id" class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm">
                                <option value="">— {{ __('Select engine') }} —</option>
                                @foreach ($cacheServices as $svc)
                                    <option value="{{ $svc->id }}">{{ ucfirst($svc->engine) }} ({{ $svc->name }})</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('new_cache_service_id')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="new_cron_expression" :value="__('Cron expression')" />
                            <x-text-input id="new_cron_expression" wire:model="new_cron_expression" class="mt-1 block w-full font-mono text-sm" placeholder="0 3 * * *" />
                            <x-input-error :messages="$errors->get('new_cron_expression')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="new_destination_id" :value="__('Destination')" />
                            <select id="new_destination_id" wire:model="new_destination_id" class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm">
                                <option value="">— {{ __('Select') }} —</option>
                                @foreach ($destinations as $dest)
                                    <option value="{{ $dest->id }}">{{ $dest->name }} ({{ $dest->provider }})</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('new_destination_id')" class="mt-1" />
                        </div>
                        <div class="sm:col-span-3">
                            <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="addSchedule">
                                <span wire:loading.remove wire:target="addSchedule">{{ __('Add schedule') }}</span>
                                <span wire:loading wire:target="addSchedule">{{ __('Saving…') }}</span>
                            </x-primary-button>
                        </div>
                    </form>
                @endif
            </div>
        </section>

        {{-- History. --}}
        <section class="dply-card overflow-hidden">
            <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <h2 class="text-base font-semibold text-brand-ink">{{ __('History') }}</h2>
                <p class="mt-1 text-sm text-brand-moss">{{ __('50 most-recent snapshots. Failed runs keep their error trail for diagnosis.') }}</p>
            </div>
            @if ($snapshots->isEmpty())
                <div class="p-6 sm:p-7"><p class="text-sm text-brand-moss">{{ __('No snapshots yet. Run one above or wait for a schedule to fire.') }}</p></div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-brand-ink/10 text-xs">
                        <thead class="bg-brand-sand/30 text-[10px] uppercase tracking-wide text-brand-mist">
                            <tr>
                                <th class="px-3 py-2 text-left font-semibold">{{ __('Started') }}</th>
                                <th class="px-3 py-2 text-left font-semibold">{{ __('Engine') }}</th>
                                <th class="px-3 py-2 text-left font-semibold">{{ __('Status') }}</th>
                                <th class="px-3 py-2 text-right font-semibold">{{ __('Bytes') }}</th>
                                <th class="px-3 py-2 text-left font-semibold">{{ __('Destination') }}</th>
                                <th class="px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-ink/5 bg-white">
                            @foreach ($snapshots as $snap)
                                @php
                                    $statusClass = match ($snap->status) {
                                        'completed' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
                                        'failed' => 'bg-rose-50 text-rose-700 ring-rose-200',
                                        default => 'bg-sky-50 text-sky-700 ring-sky-200',
                                    };
                                @endphp
                                <tr>
                                    <td class="px-3 py-2 text-brand-moss" title="{{ $snap->created_at?->toDateTimeString() }}">{{ $snap->created_at?->diffForHumans() }}</td>
                                    <td class="px-3 py-2 font-medium text-brand-ink">{{ ucfirst((string) $snap->cacheService?->engine) }}</td>
                                    <td class="px-3 py-2">
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ring-1 {{ $statusClass }}">{{ $snap->status }}</span>
                                        @if ($snap->status === 'failed' && $snap->error_message)
                                            <p class="mt-1 max-w-md truncate text-[11px] text-rose-700" title="{{ $snap->error_message }}">{{ $snap->error_message }}</p>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 text-right font-mono tabular-nums text-brand-ink">{{ $snap->bytes !== null ? \Illuminate\Support\Number::fileSize((int) $snap->bytes) : '—' }}</td>
                                    <td class="px-3 py-2 text-brand-moss">{{ $snap->backupConfiguration?->name ?? '—' }}</td>
                                    <td class="px-3 py-2 text-right">
                                        <button
                                            type="button"
                                            wire:click="deleteSnapshot('{{ $snap->id }}')"
                                            wire:confirm="{{ __('Delete this snapshot record? The S3 object is not removed.') }}"
                                            class="rounded-md p-1 text-brand-mist hover:bg-brand-sand/50 hover:text-rose-700"
                                            title="{{ __('Delete record') }}"
                                        >
                                            <x-heroicon-o-trash class="h-3.5 w-3.5" />
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
</x-server-workspace-layout>
