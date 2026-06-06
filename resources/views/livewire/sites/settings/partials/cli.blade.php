@php
    $installUrl = route('cli.install');
    $siteFlag = '--site '.$site->slug;
@endphp

<section class="dply-card overflow-hidden">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
            <x-heroicon-o-command-line class="h-5 w-5" aria-hidden="true" />
        </span>
        <div class="min-w-0 flex-1">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Terminal') }}</p>
            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('dply CLI') }}</h2>
            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                {{ __('Manage this site from your terminal after a one-time `dply login`. Revoke CLI sessions under Profile → CLI.') }}
            </p>
        </div>
        <a
            href="{{ route('profile.cli') }}"
            wire:navigate
            class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
        >
            {{ __('Install & login') }}
            <x-heroicon-m-arrow-up-right class="h-3 w-3" />
        </a>
    </div>

    <div class="grid gap-6 px-6 py-5 sm:grid-cols-2 sm:px-7">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Setup') }}</p>
            <pre class="mt-2 overflow-x-auto rounded-xl border border-brand-ink/10 bg-brand-ink px-3 py-2.5 text-[11px] leading-relaxed text-brand-cream"><code>curl -fsSL {{ $installUrl }} | bash -s -- --login
dply link --byo {{ $site->id }}</code></pre>
        </div>

        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Deploy') }}</p>
            <pre class="mt-2 overflow-x-auto rounded-xl border border-brand-ink/10 bg-brand-ink px-3 py-2.5 text-[11px] leading-relaxed text-brand-cream"><code>dply deploy --follow
dply site deploy {{ $siteFlag }} --follow
dply site logs {{ $siteFlag }} --follow
dply site status {{ $siteFlag }}</code></pre>
        </div>
    </div>

    <div class="border-t border-brand-ink/10 bg-brand-sand/10 px-6 py-3 sm:px-7">
        <p class="font-mono text-[11px] text-brand-moss">
            {{ __('Site ID:') }} <span class="text-brand-ink">{{ $site->id }}</span>
            <span class="mx-2 text-brand-mist/50" aria-hidden="true">·</span>
            {{ __('Slug:') }} <span class="text-brand-ink">{{ $site->slug }}</span>
        </p>
    </div>
</section>

{{-- In-browser CLI console --}}
<section class="dply-card overflow-hidden p-0">
    <div class="flex items-center justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-4 sm:px-7">
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('In-browser') }}</p>
            <h2 class="mt-0.5 text-sm font-semibold text-brand-ink">{{ __('CLI console') }}</h2>
            <p class="mt-0.5 text-xs text-brand-moss">{{ __('Run dply commands against this site without installing the CLI locally. Uses a short-lived session token.') }}</p>
        </div>
    </div>
    <div class="p-4 sm:p-6">
        @livewire('sites.cli-console', ['site' => $site, 'server' => $server], key('cli-console-'.$site->id))
    </div>
</section>
