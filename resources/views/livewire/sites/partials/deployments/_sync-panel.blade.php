{{-- Deploy Sync — pick several related sites (e.g. a main site + its worker)
     and deploy them together in one click. Replaces the old persistent
     "sync group": an ad-hoc multi-select per deploy, no group to maintain. --}}
@php $candidates = $this->syncCandidates; @endphp

<section class="dply-card overflow-hidden">
    <div class="flex flex-wrap items-start justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-8">
        <div class="flex min-w-0 items-start gap-3">
            <x-icon-badge>
                <x-heroicon-o-arrows-right-left class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Deploy together') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Sync deploy') }}</h2>
                <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Select the sites to ship in one go — typically this site and its worker(s) sharing the same repository. Each is deployed in parallel, exactly like its own Deploy button.') }}</p>
            </div>
        </div>
        @can('update', $site)
            <button
                type="button"
                wire:click="deployMultiple"
                wire:loading.attr="disabled"
                wire:target="deployMultiple"
                @disabled(count($syncSelectedSiteIds) === 0)
                class="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-brand-forest disabled:opacity-50"
            >
                <x-heroicon-o-rocket-launch class="h-4 w-4" wire:loading.remove wire:target="deployMultiple" />
                <span wire:loading wire:target="deployMultiple"><x-spinner variant="white" size="sm" /></span>
                {{ __('Deploy selected (:n)', ['n' => count($syncSelectedSiteIds)]) }}
            </button>
        @endcan
    </div>

    <div class="px-6 py-5 sm:px-8">
        @if ($candidates->count() <= 1)
            <p class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-cream/30 px-4 py-3 text-sm text-brand-moss">
                {{ __('No related sites found to deploy with this one. Sites are matched by shared Git repository (or the same server when no repo is set).') }}
            </p>
        @else
            <ul class="divide-y divide-brand-ink/10 overflow-hidden rounded-xl border border-brand-ink/10">
                @foreach ($candidates as $candidate)
                    <li>
                        <label class="flex cursor-pointer items-center gap-3 px-4 py-3 transition-colors hover:bg-brand-sand/30">
                            <input
                                type="checkbox"
                                wire:model.live="syncSelectedSiteIds"
                                value="{{ $candidate->id }}"
                                class="h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-sage/40"
                            />
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <span class="truncate text-sm font-semibold text-brand-ink">{{ $candidate->name }}</span>
                                    @if ($candidate->id === $site->id)
                                        <span class="rounded bg-brand-sand/60 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('this site') }}</span>
                                    @endif
                                    @if ($candidate->isWorkerSite())
                                        <span class="rounded bg-violet-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-violet-800">{{ __('worker') }}</span>
                                    @endif
                                </div>
                                <p class="mt-0.5 truncate text-xs text-brand-mist">{{ $candidate->server?->name ?? '—' }}@if ($candidate->git_branch) · {{ $candidate->git_branch }}@endif</p>
                            </div>
                        </label>
                    </li>
                @endforeach
            </ul>
            <p class="mt-3 text-xs text-brand-moss">{{ __('Sites are matched by shared Git repository (a worker runs the same code as its main site). You can deselect any you don’t want to ship this time.') }}</p>
        @endif
    </div>
</section>