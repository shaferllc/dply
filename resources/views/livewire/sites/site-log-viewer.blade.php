<section class="space-y-4" wire:key="site-log-viewer-{{ $site->id }}">
    <div
        id="dply-server-log-broadcast-context"
        class="hidden"
        aria-hidden="true"
        data-server-id="{{ $server->id }}"
        data-subscribe="{{ $logBroadcastEchoSubscribable ? '1' : '0' }}"
    ></div>

    {{-- Heads-up banner: this view scopes to the current site's vhost
         logs. Operators frequently need machine-wide logs (syslog,
         php-fpm, fleet activity) while debugging a site, so we surface
         a one-click jump to the server logs workspace right at the top
         instead of forcing them to backtrack. --}}
    <div class="flex flex-col gap-3 rounded-2xl border border-brand-ink/10 bg-brand-sand/30 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
        <div class="flex items-start gap-3">
            <x-heroicon-o-information-circle class="mt-0.5 h-5 w-5 shrink-0 text-brand-sage" />
            <p class="text-sm leading-relaxed text-brand-moss">
                {{ __('Showing this site\'s vhost + deploy logs. Need machine-wide logs (syslog, PHP-FPM, fleet activity) for :server? Open them here without leaving the page.', ['server' => $server->name]) }}
            </p>
        </div>
        <a
            href="{{ route('servers.logs', $server) }}"
            wire:navigate
            class="inline-flex shrink-0 items-center justify-center gap-2 self-start rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm transition-colors hover:border-brand-sage hover:text-brand-sage sm:self-auto"
        >
            <x-heroicon-o-server-stack class="h-4 w-4" />
            {{ __('Open server logs') }}
        </a>
    </div>

    @include('livewire.servers.partials.log-viewer-panel', ['logSources' => $logSources])
</section>
