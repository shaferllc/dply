<section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-8 space-y-4">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Recent deploys') }}</h2>
            <p class="mt-1 text-sm text-brand-moss">{{ __('See recent deploy activity for this site here. Use the server logs workspace when you need broader machine or service logs.') }}</p>
        </div>
        <a href="{{ route('servers.logs', $server) }}" wire:navigate class="inline-flex items-center gap-2 text-sm font-medium text-brand-moss hover:text-brand-ink">
            {{ __('Open server logs') }}
        </a>
    </div>

    @if ($site->deployments->isEmpty())
        <p class="text-sm text-brand-moss">{{ __('No deploys recorded yet.') }}</p>
    @else
        <ul class="space-y-3">
            @foreach ($site->deployments->take(10) as $deployment)
                <li class="rounded-xl border border-brand-ink/10 px-4 py-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="space-y-1">
                            <p class="text-sm font-semibold text-brand-ink">
                                {{ ucfirst($deployment->trigger) }} · {{ ucfirst($deployment->status) }}
                            </p>
                            <p class="text-xs text-brand-moss">
                                {{ $deployment->created_at?->diffForHumans() ?? __('Just now') }}
                                @if ($deployment->git_sha)
                                    · <span class="font-mono text-brand-ink">{{ $deployment->git_sha }}</span>
                                @endif
                            </p>
                            @if ($deployment->log_output)
                                <p class="text-sm text-brand-moss">{{ \Illuminate\Support\Str::limit($deployment->log_output, 220) }}</p>
                            @endif
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</section>

<section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-8 space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Webhook delivery log') }}</h2>
        <p class="mt-1 text-sm text-brand-moss">{{ __('Recent inbound deploy webhook attempts, including signature checks, allowed IP decisions, and result details.') }}</p>
    </div>

    @if ($site->webhookDeliveryLogs->isEmpty())
        <p class="text-sm text-brand-moss">{{ __('No deliveries recorded yet.') }}</p>
    @else
        <ul class="space-y-3">
            @foreach ($site->webhookDeliveryLogs->take(20) as $log)
                <li class="rounded-xl border border-brand-ink/10 px-4 py-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="space-y-1">
                            <p class="text-sm font-semibold text-brand-ink">{{ $log->http_status }} · {{ $log->outcome }}</p>
                            <p class="text-xs text-brand-moss">
                                {{ $log->created_at?->diffForHumans() ?? __('Just now') }}
                                @if ($log->request_ip)
                                    · <span class="font-mono text-brand-ink">{{ $log->request_ip }}</span>
                                @endif
                            </p>
                            @if ($log->detail)
                                <p class="text-sm text-brand-moss">{{ $log->detail }}</p>
                            @endif
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</section>
