<section class="space-y-4">
    <div class="dply-card p-4 sm:p-6">
        <label class="flex items-center gap-3 text-sm">
            <x-heroicon-o-magnifying-glass class="h-4 w-4 text-brand-moss" />
            <input
                type="text"
                wire:model.live.debounce.300ms="branchSearch"
                placeholder="{{ __('Filter branches by name…') }}"
                class="w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-ink focus:ring-1 focus:ring-brand-ink"
            />
        </label>
    </div>

    @if (! ($branchesResult['ok'] ?? false))
        <div class="rounded-lg border border-rose-200 bg-rose-50 p-3 text-xs text-rose-900">
            {{ $branchesResult['error'] ?? __('Could not load branches.') }}
        </div>
    @elseif (empty($branchesFiltered))
        <div class="rounded-lg border border-dashed border-brand-ink/15 bg-brand-sand/20 p-6 text-center text-sm text-brand-moss">
            @if ($branchSearch === '')
                {{ __('No branches found on this repository.') }}
            @else
                {{ __('No branches match ":q".', ['q' => $branchSearch]) }}
            @endif
        </div>
    @else
        <ul class="divide-y divide-brand-ink/10 rounded-2xl border border-brand-ink/10 bg-white shadow-sm">
            @foreach ($branchesFiltered as $branch)
                <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-3" wire:key="branch-{{ $branch['name'] }}">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <code class="font-mono text-sm text-brand-ink">{{ $branch['name'] }}</code>
                            @if ($branch['name'] === $currentBranch)
                                <span class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.12em] text-emerald-900">{{ __('deploy branch') }}</span>
                            @endif
                            @if (! empty($branch['is_default']))
                                <span class="inline-flex items-center rounded-full bg-brand-sand/40 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('default') }}</span>
                            @endif
                        </div>
                        <p class="mt-0.5 text-xs text-brand-moss">
                            @if (! empty($branch['sha']))
                                <code class="font-mono">{{ substr($branch['sha'], 0, 7) }}</code>
                            @endif
                            @if (! empty($branch['committer']))
                                · {{ $branch['committer'] }}
                            @endif
                            @if (! empty($branch['committed_at']))
                                · {{ \Illuminate\Support\Carbon::parse($branch['committed_at'])->diffForHumans() }}
                            @endif
                        </p>
                    </div>
                    @if ($branch['name'] !== $currentBranch)
                        <button
                            type="button"
                            wire:click="switchBranch('{{ $branch['name'] }}')"
                            wire:loading.attr="disabled"
                            wire:target="switchBranch('{{ $branch['name'] }}')"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-wait disabled:opacity-60"
                        >
                            <x-heroicon-o-arrow-path class="h-3.5 w-3.5" />
                            {{ __('Set as deploy branch') }}
                        </button>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</section>
