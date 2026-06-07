<div class="space-y-6">
    <section class="dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <x-icon-badge>
                <x-heroicon-o-clock class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Crons') }}</p>
                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Cron triggers') }}</h3>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                    {{ __('Scheduled invocations of the worker. Repo-declared rows come from :file; dashboard rows merge into the same list at deploy time and ship to Cloudflare alongside.', ['file' => $sourcePath]) }}
                </p>
            </div>
            <a
                href="{{ route('sites.edge.dply-yaml', ['server' => $site->server_id, 'site' => $site->id]) }}"
                class="ml-auto inline-flex shrink-0 items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 font-mono text-[10px] font-semibold uppercase tracking-wide text-brand-ink hover:bg-brand-sand/40"
                title="{{ __('Download a dply.yaml that includes repo + dashboard crons') }}"
            >
                <x-heroicon-o-arrow-down-tray class="h-3 w-3" aria-hidden="true" />
                {{ __('Generate dply.yaml') }}
            </a>
        </div>

        {{-- Repo-declared crons (read-only, source of truth) --}}
        <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
            <div class="flex items-baseline justify-between gap-2">
                <h4 class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('From :file', ['file' => $sourcePath]) }}</h4>
                <span class="inline-flex items-center gap-1 rounded-full bg-brand-sand/60 px-2 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-wide text-brand-moss">
                    {{ __('Repo-managed') }}
                </span>
            </div>
            @if ($repoCrons !== [])
                <div class="mt-2 overflow-x-auto rounded-lg border border-brand-ink/10">
                    <table class="min-w-full divide-y divide-brand-ink/8 text-xs">
                        <thead class="bg-brand-sand/30 text-left text-[10px] font-semibold uppercase tracking-wide text-brand-mist">
                            <tr>
                                <th class="px-3 py-2">{{ __('Schedule') }}</th>
                                <th class="px-3 py-2">{{ __('Handler') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-ink/8 text-brand-ink">
                            @foreach ($repoCrons as $entry)
                                <tr>
                                    <td class="px-3 py-2 font-mono">{{ $entry['schedule'] }}</td>
                                    <td class="px-3 py-2 font-mono text-brand-moss">{{ $entry['handler'] ?: __('(default)') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="mt-2 text-sm text-brand-moss">
                    {{ __('No deploy has shipped a :file with crons yet. Add a `crons:` block at the repo root and redeploy, or define rows below and we\'ll inject them on the next deploy.', ['file' => $sourcePath]) }}
                </p>
                <pre class="mt-3 overflow-x-auto rounded-lg bg-brand-ink/95 px-4 py-3 font-mono text-[11px] leading-relaxed text-brand-sand"><code>crons:
  - schedule: "*/5 * * * *"
    handler: "scheduled"
  - schedule: "0 3 * * *"
    handler: "daily"</code></pre>
            @endif
        </div>

        {{-- Dashboard-managed crons (editable) --}}
        <div class="px-6 py-4 sm:px-8">
            <div class="flex items-center justify-between gap-2">
                <h4 class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Dashboard-managed') }}</h4>
                <span wire:loading.inline-flex wire:target="addCron,removeCron" class="inline-flex items-center gap-1.5 text-[11px] text-brand-moss">
                    <x-spinner size="sm" variant="muted" />
                    {{ __('Saving…') }}
                </span>
            </div>

            @if ($dashboard_crons === [])
                <p class="mt-2 text-xs text-brand-moss">{{ __('No dashboard crons yet — add one below or commit a `crons:` block to :file.', ['file' => $sourcePath]) }}</p>
            @else
                <div class="mt-2 overflow-x-auto rounded-lg border border-brand-ink/10">
                    <table class="min-w-full divide-y divide-brand-ink/8 text-xs">
                        <thead class="bg-brand-sand/30 text-left text-[10px] font-semibold uppercase tracking-wide text-brand-mist">
                            <tr>
                                <th class="px-3 py-2">{{ __('Schedule') }}</th>
                                <th class="px-3 py-2">{{ __('Handler') }}</th>
                                <th class="px-3 py-2 text-right">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-ink/8 text-brand-ink">
                            @foreach ($dashboard_crons as $index => $entry)
                                <tr wire:key="cron-{{ $index }}-{{ $entry['schedule'] }}">
                                    <td class="px-3 py-2 font-mono">{{ $entry['schedule'] }}</td>
                                    <td class="px-3 py-2 font-mono text-brand-moss">{{ $entry['handler'] !== '' ? $entry['handler'] : __('(default)') }}</td>
                                    <td class="px-3 py-2 text-right">
                                        <button
                                            type="button"
                                            wire:click="removeCron({{ $index }})"
                                            wire:confirm="{{ __('Remove this cron?') }}"
                                            class="text-xs font-semibold text-rose-600 hover:text-rose-700"
                                        >
                                            {{ __('Remove') }}
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <form wire:submit.prevent="addCron" class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                <div>
                    <label for="new-schedule" class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Schedule') }}</label>
                    <input
                        id="new-schedule"
                        type="text"
                        wire:model="new_schedule"
                        class="mt-1 block w-full rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 font-mono text-xs text-brand-ink focus:border-brand-forest focus:ring-brand-forest"
                        placeholder="*/5 * * * *"
                        autocomplete="off"
                    />
                    @error('new_schedule') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="new-handler" class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Handler (optional)') }}</label>
                    <input
                        id="new-handler"
                        type="text"
                        wire:model="new_handler"
                        class="mt-1 block w-full rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 font-mono text-xs text-brand-ink focus:border-brand-forest focus:ring-brand-forest"
                        placeholder="scheduled"
                        autocomplete="off"
                    />
                </div>
                <div>
                    <button type="submit" class="rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-ink/90">
                        {{ __('Add cron') }}
                    </button>
                </div>
            </form>

            <p class="mt-3 text-[11px] text-brand-mist">
                {{ __('Cron times are UTC. Cloudflare runs schedules at-most-once across all colos. Dashboard rows merge with :file at deploy time — both sets ship to Cloudflare on the next deploy.', ['file' => $sourcePath]) }}
            </p>
        </div>
    </section>
</div>
