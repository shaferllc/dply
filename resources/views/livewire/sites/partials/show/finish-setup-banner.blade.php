{{--
    Prominent, NON-forcing "finish setting up" card on the Overview for a site
    held in the post-repo-connect setup flow (repo connected, never deployed,
    required env still unmet — Site::needsFirstDeploySetup()). The site stays
    live (splash serving); this never hijacks navigation — it just resumes the
    wizard at the first incomplete step. See the SiteSetup wizard.
--}}
@php
    $setupMissingCount = count($site->unsatisfiedRequiredEnvKeys());
    $setupScanFailed = $site->setupScanFailed();
@endphp
<div class="mt-6 overflow-hidden rounded-2xl border border-brand-forest/25 bg-gradient-to-br from-white to-brand-sage/[0.07] shadow-sm">
    <div class="flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between sm:p-6">
        <div class="flex items-start gap-4">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-brand-forest/10 text-brand-forest ring-1 ring-brand-forest/15">
                <x-heroicon-o-wrench-screwdriver class="h-6 w-6" />
            </span>
            <div class="min-w-0">
                <h3 class="text-base font-semibold text-brand-ink">{{ __('Finish setting up your site') }}</h3>
                <p class="mt-1 text-sm text-brand-moss">
                    @if ($setupScanFailed)
                        {{ __("We couldn't read your repository to detect what it needs — open setup to fix access and continue.") }}
                    @elseif ($setupMissingCount > 0)
                        {{ trans_choice(':count required environment variable still needs a value before your first deploy.|:count required environment variables still need values before your first deploy.', $setupMissingCount, ['count' => $setupMissingCount]) }}
                    @else
                        {{ __('Configure resources and run your first deploy when you’re ready.') }}
                    @endif
                </p>
            </div>
        </div>
        <a href="{{ route('sites.repository', [$server, $site, 'repo_tab' => 'setup']) }}" wire:navigate
            class="inline-flex shrink-0 items-center justify-center gap-2 rounded-lg bg-brand-forest px-4 py-2.5 text-sm font-semibold text-brand-cream transition-colors hover:bg-brand-forest/90">
            <x-heroicon-o-arrow-right class="h-4 w-4" />
            {{ __('Resume setup') }}
        </a>
    </div>
</div>
