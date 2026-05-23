<div>
    <div class="dply-page-shell py-8 sm:py-10">
        <x-breadcrumb-trail :items="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Infrastructure'), 'href' => route('infrastructure.index'), 'icon' => 'rectangle-group'],
            ['label' => __('Edge'), 'icon' => 'globe-alt'],
        ]" />

        <header class="max-w-2xl">
            <span class="inline-flex rounded-full bg-brand-ink/[0.06] px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-brand-moss">
                {{ __('Coming soon') }}
            </span>
            <h1 class="mt-4 text-2xl font-semibold tracking-tight text-brand-ink">{{ __('Edge') }}</h1>
            <p class="mt-2 text-sm leading-relaxed text-brand-moss">
                {{ __('JavaScript frameworks, static sites, preview deployments, and CDN-style delivery for :org.', ['org' => $org->name]) }}
            </p>
        </header>

        <div class="mt-8 max-w-2xl rounded-2xl border border-brand-ink/10 bg-white/70 p-6 shadow-sm ring-1 ring-brand-ink/[0.04]">
            <p class="text-sm leading-6 text-brand-moss">
                {{ __('dply Edge is the home for front-end and static workloads — git-connected builds, branch previews, and global edge delivery. It is separate from Cloud apps (long-running PHP and Rails containers) and BYO server sites.') }}
            </p>
            <p class="mt-4 text-sm font-medium text-brand-mist">
                {{ __('This surface is not available yet. Use Infrastructure to manage servers, cloud apps, and serverless functions in the meantime.') }}
            </p>
            <a
                href="{{ route('infrastructure.index') }}"
                wire:navigate
                class="mt-6 inline-flex items-center gap-2 rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
            >
                <x-heroicon-o-rectangle-group class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                {{ __('Back to infrastructure') }}
            </a>
        </div>
    </div>
</div>
