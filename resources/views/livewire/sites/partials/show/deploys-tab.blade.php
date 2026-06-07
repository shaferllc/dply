<section class="dply-card overflow-hidden">
    <div class="flex flex-col gap-4 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:px-7">
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Deploy') }}</p>
            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Deployments') }}</h3>
            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Deploy, view history, and roll back releases from the Deployments workspace.') }}</p>
        </div>
        <a href="{{ route('sites.deployments.index', [$server, $site]) }}" wire:navigate class="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90">
            <x-heroicon-o-code-bracket-square class="h-4 w-4" />
            {{ __('Open Deployments') }}
        </a>
    </div>
    @if ($this->latestDeployment !== null)
        <div class="px-6 py-5 sm:px-8">
            <p class="text-sm text-brand-moss">
                {{ __('Latest:') }}
                <span class="font-semibold text-brand-ink">{{ $this->latestDeployment->status }}</span>
                @if ($this->latestDeployment->started_at)
                    · {{ $this->latestDeployment->started_at->diffForHumans() }}
                @endif
            </p>
        </div>
    @endif
</section>
