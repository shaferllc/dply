<div>
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <div>
                <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Schedules') }}</p>
                <p class="mt-1 text-sm text-brand-moss">{{ __('UTC cron expressions. Applied on the next deploy.') }}</p>
            </div>
            <span wire:loading.inline-flex wire:target="addCron,removeCron,confirmActionModal" class="inline-flex items-center gap-1.5 text-[11px] text-brand-moss">
                <x-spinner size="sm" variant="muted" />
                {{ __('Saving…') }}
            </span>
        </div>

        @if ($dashboard_crons === [])
            <p class="mt-3 text-sm text-brand-moss">{{ __('No dashboard schedules yet.') }}</p>
        @else
            <ul class="mt-3 divide-y divide-brand-ink/8 rounded-xl border border-brand-ink/10">
                @foreach ($dashboard_crons as $index => $entry)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-3" wire:key="cron-{{ $index }}-{{ $entry['schedule'] }}">
                        <div class="min-w-0 font-mono text-sm">
                            <span class="text-brand-ink">{{ $entry['schedule'] }}</span>
                            <span class="text-brand-moss"> · {{ $entry['handler'] !== '' ? $entry['handler'] : __('default') }}</span>
                        </div>
                        @can('update', $site)
                            <button
                                type="button"
                                wire:click="openConfirmActionModal('removeCron', @js([$index]), @js(__('Remove schedule')), @js(__('Remove :schedule?', ['schedule' => $entry['schedule']])), @js(__('Remove')), true)"
                                class="text-xs font-medium text-rose-700 hover:text-rose-900 dark:text-rose-400"
                            >
                                {{ __('Remove') }}
                            </button>
                        @endcan
                    </li>
                @endforeach
            </ul>
        @endif

        @can('update', $site)
            <form wire:submit.prevent="addCron" class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                <div>
                    <label for="new-schedule" class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Schedule') }}</label>
                    <input
                        id="new-schedule"
                        type="text"
                        wire:model="new_schedule"
                        class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink focus:border-brand-forest focus:ring-brand-forest dark:border-brand-mist/20 dark:bg-zinc-900"
                        placeholder="*/5 * * * *"
                        autocomplete="off"
                    />
                    @error('new_schedule') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="new-handler" class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Handler') }}</label>
                    <input
                        id="new-handler"
                        type="text"
                        wire:model="new_handler"
                        class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink focus:border-brand-forest focus:ring-brand-forest dark:border-brand-mist/20 dark:bg-zinc-900"
                        placeholder="scheduled"
                        autocomplete="off"
                    />
                </div>
                <button type="submit" class="rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-ink/90">
                    {{ __('Add') }}
                </button>
            </form>
        @endcan
    </section>

    <details class="group" @if ($repoCrons !== []) open @endif>
        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 bg-brand-sand/10 px-5 py-3.5 text-sm font-semibold text-brand-ink hover:bg-brand-sand/20 sm:px-6 [&::-webkit-details-marker]:hidden">
            <span class="inline-flex items-center gap-2">
                {{ __('Advanced') }}
                @if ($repoCrons !== [])
                    <span class="rounded-full bg-brand-sand/60 px-2 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-wide text-brand-moss">
                        {{ count($repoCrons) }}
                    </span>
                @endif
            </span>
            <x-heroicon-m-chevron-down class="h-4 w-4 text-brand-mist transition group-open:rotate-180" />
        </summary>

        <div class="space-y-4 border-t border-brand-ink/10 px-5 py-4 sm:px-6">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('From :file', ['file' => $sourcePath]) }}</p>
                <a
                    href="{{ route('sites.edge.dply-yaml', ['server' => $site->server_id, 'site' => $site->id]) }}"
                    class="inline-flex items-center gap-1 text-xs font-medium text-brand-sage hover:underline"
                >
                    <x-heroicon-o-arrow-down-tray class="h-3.5 w-3.5" aria-hidden="true" />
                    {{ __('Generate :file', ['file' => $sourcePath]) }}
                </a>
            </div>

            @if ($repoCrons !== [])
                <ul class="divide-y divide-brand-ink/8 rounded-xl border border-brand-ink/10">
                    @foreach ($repoCrons as $entry)
                        <li class="px-4 py-2.5 font-mono text-xs">
                            <span class="text-brand-ink">{{ $entry['schedule'] }}</span>
                            <span class="text-brand-moss"> · {{ $entry['handler'] ?: __('default') }}</span>
                        </li>
                    @endforeach
                </ul>
                <p class="text-[11px] text-brand-mist">{{ __('Repo schedules are read-only here. Dashboard rows merge with them on deploy.') }}</p>
            @else
                <p class="text-sm text-brand-moss">{{ __('None declared in :file yet.', ['file' => $sourcePath]) }}</p>
            @endif

            <x-edge-yaml-example :file="$sourcePath" :hint="__('Commit schedules in the repo, or add them above in the dashboard.')">
crons:
  - schedule: "*/5 * * * *"
    handler: "scheduled"
  - schedule: "0 3 * * *"
    handler: "daily"
            </x-edge-yaml-example>
        </div>
    </details>

    @include('livewire.partials.confirm-action-modal')
</div>
