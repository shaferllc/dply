<section class="space-y-4" wire:key="site-log-viewer-{{ $site->id }}">
    <div
        id="dply-server-log-broadcast-context"
        class="hidden"
        aria-hidden="true"
        data-server-id="{{ $server->id }}"
        data-subscribe="{{ $logBroadcastEchoSubscribable ? '1' : '0' }}"
    ></div>

    {{-- This view scopes to the current site's vhost + deploy logs.
         Operators frequently need machine-wide logs (syslog, php-fpm,
         fleet activity) while debugging a site, so we surface a one-click
         jump to the server logs workspace in the hero top action. --}}
    <x-hero-card
        :eyebrow="__('Logs')"
        :title="__('Site logs')"
        :description="__('Showing this site\'s vhost and deploy logs. Need machine-wide logs (syslog, PHP-FPM, fleet activity)? Open the server logs without leaving the page.')"
        icon="document-text"
    >
        <x-slot:topAction>
            <a
                href="{{ route('servers.logs', $server) }}"
                wire:navigate
                class="inline-flex shrink-0 items-center justify-center gap-2 self-start rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:border-brand-sage hover:text-brand-sage sm:self-auto"
            >
                <x-heroicon-o-server-stack class="h-4 w-4" />
                {{ __('Open server logs') }}
            </a>
        </x-slot:topAction>
    </x-hero-card>

    @include('livewire.servers.partials.log-viewer-panel', ['logSources' => $logSources])
</section>
