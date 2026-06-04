<x-server-workspace-layout
    :server="$server"
    active="redis-snapshots"
    :title="__('Snapshots')"
    :description="__('Point-in-time RDB snapshots of the cache engine on this server. Run on demand or on a cron, stored in your S3-style backup destination.')"
>
    @if (in_array('redis-snapshots', config('server_workspace.coming_soon_keys', []), true))
        <x-workspace-coming-soon
            :server="$server"
            icon="heroicon-o-camera"
            :title="__('Redis snapshots')"
            :description="__('Point-in-time RDB snapshots of the cache engine on this server — run on demand or on a schedule, streamed to your S3-style backup destination, with one-click restore.')"
            :eyebrow="__('RDB snapshot preview')"
            :heroNote="__('Snapshots will run against the cache engine on :server when it ships.', ['server' => $server->name])"
            :lines="[
                ['tone' => 'cmd', 'text' => '~ $ redis-cli BGSAVE'],
                ['tone' => 'muted', 'text' => 'Background saving started'],
                ['tone' => 'muted', 'text' => 'snapshot-2026-06-04.rdb → s3://backups/  (412 MB)'],
                ['tone' => 'ok', 'text' => '3 snapshots retained · next run in 6h'],
            ]"
            :features="[
                ['icon' => 'camera', 'title' => __('On-demand & scheduled'), 'body' => __('Trigger a snapshot now or run BGSAVE on a cron you control.')],
                ['icon' => 'cloud-arrow-up', 'title' => __('Off-box storage'), 'body' => __('Stream the RDB straight to your existing S3-style backup destination.')],
                ['icon' => 'arrow-uturn-left', 'title' => __('Point-in-time restore'), 'body' => __('Restore the cache to any retained snapshot in one click.')],
                ['icon' => 'clock', 'title' => __('Retention windows'), 'body' => __('Keep the last N snapshots and prune the rest automatically.')],
            ]"
        />
    @else
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <div class="space-y-8">
        {{-- Run a snapshot now. --}}
        <section class="dply-card overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-camera class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Snapshot') }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Run a snapshot now') }}</h2>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('BGSAVE on the primary engine, copy the RDB file to your destination, and record the result below.') }}</p>
                </div>
            </div>

            <div class="px-6 py-6 sm:px-7">
                @if ($cacheServices->isEmpty())
                    <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-6 py-8 text-center">
                        <span class="mx-auto flex h-10 w-10 items-center justify-center rounded-2xl bg-amber-50 text-amber-700 ring-1 ring-amber-200">
                            <x-heroicon-o-exclamation-triangle class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <p class="mt-3 text-sm font-semibold text-brand-ink">{{ __('No cache service installed') }}</p>
                        <p class="mx-auto mt-1 max-w-md text-xs leading-relaxed text-brand-moss">
                            {{ __('No redis-family cache service is installed on this server yet. Install one from the Caches workspace first, then return here to snapshot it.') }}
                        </p>
                    </div>
                @elseif ($destinations->isEmpty())
                    <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-6 py-8 text-center">
                        <span class="mx-auto flex h-10 w-10 items-center justify-center rounded-2xl bg-amber-50 text-amber-700 ring-1 ring-amber-200">
                            <x-heroicon-o-cloud class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <p class="mt-3 text-sm font-semibold text-brand-ink">{{ __('No backup destination configured') }}</p>
                        <p class="mx-auto mt-1 max-w-md text-xs leading-relaxed text-brand-moss">
                            {{ __('Add an S3-style backup destination from Settings → Backups, then come back to capture your first snapshot.') }}
                        </p>
                    </div>
                @else
                    <form wire:submit="runNow" class="flex flex-wrap items-end gap-3">
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
            </div>
        </section>

        {{-- Schedules. --}}
        <section class="dply-card overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-clock class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Schedules') }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Recurring snapshots') }}</h2>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Cron-driven captures controlled by the dply control plane. Each fires BGSAVE + upload at the configured cadence.') }}</p>
                </div>
            </div>

            <div class="px-6 py-6 sm:px-7">
                @if ($schedules->isEmpty())
                    <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-6 py-8 text-center">
                        <span class="mx-auto flex h-10 w-10 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                            <x-heroicon-o-clock class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <p class="mt-3 text-sm font-semibold text-brand-ink">{{ __('No schedules yet') }}</p>
                        <p class="mx-auto mt-1 max-w-md text-xs leading-relaxed text-brand-moss">
                            {{ __('Pick an engine, a cron expression, and a destination below to start automatic snapshots — e.g. ') }}
                            <code class="rounded bg-white/70 px-1 py-0.5 font-mono text-[11px] text-brand-ink ring-1 ring-brand-ink/10">0 3 * * *</code>
                            {{ __('for a nightly 3 AM run.') }}
                        </p>
                    </div>
                @else
                    <ul class="divide-y divide-brand-ink/10 rounded-xl border border-brand-ink/10">
                        @foreach ($schedules as $schedule)
                            <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
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
                                        class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40"
                                    >
                                        @if ($schedule->is_active)
                                            <x-heroicon-o-pause-circle class="h-3.5 w-3.5" aria-hidden="true" />
                                            {{ __('Pause') }}
                                        @else
                                            <x-heroicon-o-play-circle class="h-3.5 w-3.5" aria-hidden="true" />
                                            {{ __('Resume') }}
                                        @endif
                                    </button>
                                    <button
                                        type="button"
                                        wire:click="deleteSchedule('{{ $schedule->id }}')"
                                        wire:confirm="{{ __('Delete this schedule?') }}"
                                        class="inline-flex items-center gap-1 rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-medium text-red-700 hover:bg-red-100"
                                    >
                                        <x-heroicon-o-trash class="h-3.5 w-3.5" aria-hidden="true" />
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
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-archive-box class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('History') }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Recent snapshots') }}</h2>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('50 most-recent runs. Failed snapshots keep their error trail for diagnosis.') }}</p>
                </div>
            </div>

            <div class="px-6 py-6 sm:px-7">
                @if ($snapshots->isEmpty())
                    <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-6 py-8 text-center">
                        <span class="mx-auto flex h-10 w-10 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                            <x-heroicon-o-archive-box class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <p class="mt-3 text-sm font-semibold text-brand-ink">{{ __('No snapshots yet') }}</p>
                        <p class="mx-auto mt-1 max-w-md text-xs leading-relaxed text-brand-moss">{{ __('Run one above or wait for a schedule to fire. Completed snapshots show up here with their byte size, destination, and a delete affordance for the record only — the S3 object stays put.') }}</p>
                    </div>
                @else
                    <div class="overflow-x-auto rounded-xl border border-brand-ink/10">
                        <table class="min-w-full divide-y divide-brand-ink/10 text-xs">
                            <thead class="bg-brand-sand/40 text-left text-[10px] font-semibold uppercase tracking-wide text-brand-mist">
                                <tr>
                                    <th class="px-4 py-3">{{ __('Started') }}</th>
                                    <th class="px-4 py-3">{{ __('Engine') }}</th>
                                    <th class="px-4 py-3">{{ __('Status') }}</th>
                                    <th class="px-4 py-3 text-right">{{ __('Bytes') }}</th>
                                    <th class="px-4 py-3">{{ __('Destination') }}</th>
                                    <th class="px-4 py-3"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-brand-ink/10 bg-white">
                                @foreach ($snapshots as $snap)
                                    @php
                                        $statusClass = match ($snap->status) {
                                            'completed' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
                                            'failed' => 'bg-rose-50 text-rose-700 ring-rose-200',
                                            default => 'bg-sky-50 text-sky-700 ring-sky-200',
                                        };
                                    @endphp
                                    <tr>
                                        <td class="px-4 py-3 text-brand-moss" title="{{ $snap->created_at?->toDateTimeString() }}">{{ $snap->created_at?->diffForHumans() }}</td>
                                        <td class="px-4 py-3 font-medium text-brand-ink">{{ ucfirst((string) $snap->cacheService?->engine) }}</td>
                                        <td class="px-4 py-3">
                                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ring-1 {{ $statusClass }}">{{ $snap->status }}</span>
                                            @if ($snap->status === 'failed' && $snap->error_message)
                                                <p class="mt-1 max-w-md truncate text-[11px] text-rose-700" title="{{ $snap->error_message }}">{{ $snap->error_message }}</p>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-right font-mono tabular-nums text-brand-ink">{{ $snap->bytes !== null ? \Illuminate\Support\Number::fileSize((int) $snap->bytes) : '—' }}</td>
                                        <td class="px-4 py-3 text-brand-moss">{{ $snap->backupConfiguration?->name ?? '—' }}</td>
                                        <td class="px-4 py-3 text-right">
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
            </div>
        </section>
    </div>
    @endif
</x-server-workspace-layout>
