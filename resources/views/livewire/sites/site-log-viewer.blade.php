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
    <div class="dply-card overflow-hidden">
        <div class="flex flex-col gap-3 bg-brand-sand/20 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-7">
            <div class="flex min-w-0 items-start gap-3">
                <x-icon-badge>
                    <x-heroicon-o-document-text class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Logs') }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Site logs') }}</h2>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                        {{ __('Showing this site\'s vhost + deploy logs. Need machine-wide logs (syslog, PHP-FPM, fleet activity) for :server? Open them here without leaving the page.', ['server' => $server->name]) }}
                    </p>
                </div>
            </div>
            <a
                href="{{ route('servers.logs', $server) }}"
                wire:navigate
                class="inline-flex shrink-0 items-center justify-center gap-2 self-start rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:border-brand-sage hover:text-brand-sage sm:self-auto"
            >
                <x-heroicon-o-server-stack class="h-4 w-4" />
                {{ __('Open server logs') }}
            </a>
        </div>
    </div>

    @include('livewire.servers.partials.log-viewer-panel', ['logSources' => $logSources])
</section>
