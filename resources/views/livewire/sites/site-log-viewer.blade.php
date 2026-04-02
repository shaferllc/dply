<section class="space-y-4" wire:key="site-log-viewer-{{ $site->id }}">
    <div
        id="dply-server-log-broadcast-context"
        class="hidden"
        aria-hidden="true"
        data-server-id="{{ $server->id }}"
        data-subscribe="{{ $logBroadcastEchoSubscribable ? '1' : '0' }}"
    ></div>

    @include('livewire.servers.partials.remote-ssh-stream-panel', ['logViewportLines' => $logDisplayLines])

    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Site logs') }}</h2>
            <p class="mt-1 text-sm text-brand-moss">
                {{ __('Platform activity, deploy webhooks, and this vhost’s web server logs. Tail and display options are shared with the server logs workspace for this machine.') }}
            </p>
        </div>
        <a
            href="{{ route('servers.logs', $server) }}"
            wire:navigate
            class="inline-flex shrink-0 items-center gap-2 text-sm font-medium text-brand-moss hover:text-brand-ink"
        >
            {{ __('Open server logs') }}
            <span aria-hidden="true">&rarr;</span>
        </a>
    </div>

    @include('livewire.servers.partials.log-viewer-panel', ['logSources' => $logSources])
</section>
