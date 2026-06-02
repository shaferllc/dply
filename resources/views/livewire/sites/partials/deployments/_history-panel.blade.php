<section class="dply-card overflow-hidden">
    <div class="flex flex-wrap items-start justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-8">
        <div class="flex min-w-0 items-start gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-clock class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('History') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Deployment history') }}</h2>
                <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                    {{ trans_choice('{0} No deployments yet|{1} :count deployment|[2,*] :count deployments', $deployments->total(), ['count' => $deployments->total()]) }}
                </p>
            </div>
        </div>
    </div>

    <div class="flex flex-wrap items-end gap-3 border-b border-brand-ink/10 bg-white px-6 py-4 sm:px-8">
        <div>
            <label for="status_filter" class="block text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Status') }}</label>
            <select id="status_filter" wire:model.live="statusFilter" class="mt-1 rounded-lg border border-brand-ink/15 px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-2 focus:ring-brand-sage/30">
                <option value="">{{ __('Any') }}</option>
                @foreach ($statuses as $s)
                    <option value="{{ $s }}">{{ $s }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="trigger_filter" class="block text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Trigger') }}</label>
            <select id="trigger_filter" wire:model.live="triggerFilter" class="mt-1 rounded-lg border border-brand-ink/15 px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-2 focus:ring-brand-sage/30">
                <option value="">{{ __('Any') }}</option>
                @foreach ($triggers as $t)
                    <option value="{{ $t }}">{{ $t }}</option>
                @endforeach
            </select>
        </div>
        @if ($statusFilter !== '' || $triggerFilter !== '')
            <button type="button" wire:click="clearFilters" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40">
                <x-heroicon-m-x-mark class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                {{ __('Clear filters') }}
            </button>
        @endif
    </div>

    @if ($deployments->isEmpty())
        <div class="px-6 py-12 text-center text-sm text-brand-moss sm:px-8">
            @if ($statusFilter !== '' || $triggerFilter !== '')
                {{ __('No deployments match the current filters.') }}
            @else
                {{ __('No deployments yet. Trigger a deploy to see it here.') }}
            @endif
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                <thead class="bg-brand-sand/10 text-left text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-mist">
                    <tr>
                        <th class="px-6 py-3 sm:px-8">{{ __('Status') }}</th>
                        <th class="px-4 py-3">{{ __('Started') }}</th>
                        <th class="px-4 py-3">{{ __('Finished') }}</th>
                        <th class="px-4 py-3">{{ __('Duration') }}</th>
                        <th class="px-4 py-3">{{ __('Trigger') }}</th>
                        <th class="px-4 py-3">{{ __('Commit') }}</th>
                        <th class="px-4 py-3">{{ __('Phases') }}</th>
                        <th class="px-6 py-3 sm:px-8">{{ __('Deploy ID') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-ink/10">
                    @foreach ($deployments as $deployment)
                        <tr class="transition-colors hover:bg-brand-sand/20">
                            <td class="px-6 py-3 sm:px-8">
                                <span @class([
                                    'inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] ring-1 ring-inset',
                                    'bg-emerald-50 text-emerald-800 ring-emerald-200' => $deployment->status === 'success',
                                    'bg-rose-50 text-rose-800 ring-rose-200' => $deployment->status === 'failed',
                                    'bg-amber-50 text-amber-900 ring-amber-200' => $deployment->status === 'running',
                                    'bg-brand-sand/60 text-brand-ink ring-brand-ink/10' => ! in_array($deployment->status, ['success', 'failed', 'running']),
                                ])>{{ $deployment->status }}</span>
                                @if ($deployment->exit_code !== null && $deployment->exit_code !== 0)
                                    <span class="mt-1 block font-mono text-[10px] text-rose-700">{{ __('exit :code', ['code' => $deployment->exit_code]) }}</span>
                                @endif
                                @if ($deployment->status === 'failed' && ops_copilot_active())
                                    <a
                                        href="{{ route('fleet.copilot', ['site' => $site->id]) }}"
                                        wire:navigate
                                        class="mt-1 inline-flex items-center gap-1 text-[10px] font-semibold text-brand-forest hover:text-brand-sage"
                                    >
                                        {{ __('Explain failure') }}
                                        <x-heroicon-m-arrow-top-right-on-square class="h-3 w-3" aria-hidden="true" />
                                    </a>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-brand-moss">
                                @if ($deployment->started_at)
                                    <span title="{{ $deployment->started_at->toIso8601String() }}">{{ $deployment->started_at->diffForHumans() }}</span>
                                @else
                                    <span class="text-brand-mist">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-brand-moss">
                                @if ($deployment->finished_at)
                                    <span title="{{ $deployment->finished_at->toIso8601String() }}">{{ $deployment->finished_at->diffForHumans() }}</span>
                                @else
                                    <span class="text-brand-mist">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 font-mono text-xs text-brand-moss">
                                @if ($deployment->phaseTotalDurationMs() > 0)
                                    {{ number_format($deployment->phaseTotalDurationMs() / 1000, 1) }}s
                                @elseif ($deployment->started_at && $deployment->finished_at)
                                    {{ $deployment->started_at->diffInSeconds($deployment->finished_at) }}s
                                @else
                                    <span class="font-sans text-brand-mist">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-brand-ink">{{ $deployment->trigger ?: '—' }}</td>
                            <td class="px-4 py-3 font-mono text-xs">
                                @if ($deployment->git_sha)
                                    <span class="rounded bg-brand-sand/60 px-1.5 py-0.5 font-semibold text-brand-sage" title="{{ $deployment->git_sha }}">{{ \Illuminate\Support\Str::limit($deployment->git_sha, 7, '') }}</span>
                                @else
                                    <span class="font-sans text-brand-mist">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-1">
                                    @foreach (['build', 'swap', 'release', 'restart'] as $phase)
                                        @if ($deployment->hasPhase($phase))
                                            <span @class([
                                                'inline-flex items-center rounded-full px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-[0.1em] ring-1 ring-inset',
                                                'bg-emerald-50 text-emerald-800 ring-emerald-200' => $deployment->phaseOk($phase),
                                                'bg-rose-50 text-rose-800 ring-rose-200' => ! $deployment->phaseOk($phase),
                                            ])>{{ $phase }}</span>
                                        @endif
                                    @endforeach
                                </div>
                            </td>
                            <td class="px-6 py-3 sm:px-8">
                                <a href="{{ route('sites.deployments.show', ['server' => $server, 'site' => $site, 'deployment' => $deployment]) }}" wire:navigate class="select-all rounded bg-brand-sand/60 px-1.5 py-0.5 font-mono text-[10px] font-semibold text-brand-sage transition-colors hover:bg-brand-sage/15 hover:text-brand-forest">{{ $deployment->id }}</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($deployments->hasPages())
            <div class="border-t border-brand-ink/10 bg-white px-6 py-4 sm:px-8">
                {{ $deployments->links() }}
            </div>
        @endif
    @endif
</section>
