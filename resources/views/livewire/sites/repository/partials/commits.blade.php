<section class="space-y-6">
    {{-- The page-level "No repository connected" card covers the empty case. --}}
    @if ($currentRepositoryUrl !== '')
        <div class="dply-card overflow-hidden">
            <div class="flex flex-col gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:flex-row sm:items-center sm:justify-between sm:gap-6 sm:px-7">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                        <x-heroicon-o-clock class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('History') }}</p>
                        <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Commit history') }}</h2>
                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                            {{ __('Branch:') }} <code class="font-mono text-brand-ink">{{ $branchInUse }}</code>
                            @if (! empty($commitsResult['remote_label']))
                                · <span class="text-brand-mist">{{ $commitsResult['remote_label'] }}</span>
                            @endif
                        </p>
                    </div>
                </div>
                <div class="relative w-full shrink-0 sm:w-64">
                    <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-brand-mist" aria-hidden="true" />
                    <input type="search" wire:model.live.debounce.300ms="commitFilter"
                        placeholder="{{ __('Filter commits') }}"
                        class="dply-input pl-9" />
                </div>
            </div>

            <div class="px-2 py-2 sm:px-3">
                @if (! ($commitsResult['ok'] ?? false))
                    <div class="m-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
                        {{ $commitsResult['error'] ?? __('Could not load commits.') }}
                    </div>
                @elseif ($commitsFiltered === [])
                    <div class="px-6 py-12 text-center text-sm text-brand-moss">
                        @if (trim($commitFilter) !== '')
                            {{ __('No commits match your filter.') }}
                        @else
                            {{ __('No commits yet, or the branch has no history.') }}
                        @endif
                    </div>
                @else
                    <ul role="list" class="divide-y divide-brand-ink/10">
                        @foreach ($commitsFiltered as $commit)
                            @php($isDeployed = $lastDeployedSha !== null && $lastDeployedSha !== '' && str_starts_with((string) $commit['sha'], (string) $lastDeployedSha))
                            <li class="grid grid-cols-[1fr_auto] items-center gap-4 px-4 py-3" wire:key="commit-{{ $commit['sha'] }}">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <code class="rounded bg-brand-sand/40 px-1.5 py-0.5 font-mono text-[11px] font-semibold text-brand-ink">{{ $commit['short_sha'] }}</code>
                                        @if ($isDeployed)
                                            <span class="inline-flex items-center gap-1 rounded-full bg-brand-sage/15 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-brand-forest ring-1 ring-brand-sage/20">
                                                <span class="inline-flex h-1.5 w-1.5 rounded-full bg-brand-sage"></span>
                                                {{ __('Deployed') }}
                                            </span>
                                        @endif
                                    </div>
                                    <p class="mt-1 truncate text-sm font-medium text-brand-ink">{{ $commit['message'] }}</p>
                                    <p class="mt-0.5 truncate text-xs text-brand-moss">
                                        {{ $commit['author_name'] }}
                                        @if (! empty($commit['committed_at']))
                                            · {{ \Illuminate\Support\Carbon::parse($commit['committed_at'])->diffForHumans() }}
                                        @endif
                                    </p>
                                </div>
                                @if (! empty($commit['html_url']))
                                    <a href="{{ $commit['html_url'] }}" target="_blank" rel="noopener noreferrer"
                                        class="shrink-0 inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                        <x-heroicon-o-arrow-top-right-on-square class="h-3.5 w-3.5" aria-hidden="true" />
                                    </a>
                                @endif
                            </li>
                        @endforeach
                    </ul>

                    @php($commitsPageNum = (int) ($commitsResult['page'] ?? $commitsPage ?? 1))
                    @php($commitsHasMore = (bool) ($commitsResult['has_more'] ?? false))
                    @if ($commitsHasMore || $commitsPageNum > 1)
                        <div class="flex items-center justify-between gap-3 border-t border-brand-ink/10 px-4 py-3 sm:px-6">
                            <button
                                type="button"
                                wire:click="changeCommitsPage(-1)"
                                wire:loading.attr="disabled"
                                @disabled($commitsPageNum <= 1)
                                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <x-heroicon-m-chevron-left class="h-3.5 w-3.5" aria-hidden="true" />
                                {{ __('Newer') }}
                            </button>
                            <span class="text-xs text-brand-moss">{{ __('Page :n', ['n' => $commitsPageNum]) }}</span>
                            <button
                                type="button"
                                wire:click="changeCommitsPage(1)"
                                wire:loading.attr="disabled"
                                @disabled(! $commitsHasMore)
                                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {{ __('Older') }}
                                <x-heroicon-m-chevron-right class="h-3.5 w-3.5" aria-hidden="true" />
                            </button>
                        </div>
                    @endif
                @endif
            </div>
        </div>
    @endif
</section>
