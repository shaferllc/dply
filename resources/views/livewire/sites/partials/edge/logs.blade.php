<div class="space-y-6">
    @include('livewire.sites.partials.edge.observability-nav', ['activeObservabilitySection' => 'logs'])

    @include('livewire.sites.partials.edge.logs-callout')

    @include('livewire.sites.partials.edge.live-request-tail')

<section class="dply-card overflow-hidden">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <x-icon-badge>
            <x-heroicon-o-document-text class="h-5 w-5" aria-hidden="true" />
        </x-icon-badge>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Logs') }}</p>
            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Build & deploy logs') }}</h3>
            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Recent Edge deployments and build output. For CDN visitor traffic, open Traffic & analytics.') }}</p>
        </div>
    </div>

    @php
        $hasBuilding = $edgeDeployments->contains(fn ($d) => in_array($d->status, [\App\Models\EdgeDeployment::STATUS_BUILDING, \App\Models\EdgeDeployment::STATUS_PUBLISHING], true));
    @endphp

    <div @if ($hasBuilding) wire:poll.5s="refreshEdgeLogDeployments" @endif>
        @if ($edgeDeployments->isEmpty())
            <div class="px-6 py-8 text-center text-sm text-brand-moss sm:px-8">
                {{ __('No activity yet — trigger a deploy from Overview.') }}
            </div>
        @else
            <ul class="divide-y divide-brand-ink/8">
                @foreach ($edgeDeployments as $deployment)
                    @php
                        $depBadge = match ($deployment->status) {
                            \App\Models\EdgeDeployment::STATUS_LIVE => 'text-emerald-700 dark:text-emerald-400',
                            \App\Models\EdgeDeployment::STATUS_FAILED => 'text-rose-700 dark:text-rose-400',
                            default => 'text-brand-moss',
                        };
                        $failureReason = $deployment->failure_reason;
                        $buildLogLoaded = isset($edgeDeploymentBuildLogsLoaded[$deployment->id]);
                        $loadedBuildLog = $buildLogLoaded ? $this->edgeDeploymentBuildLog($deployment->id) : null;
                    @endphp
                    <li class="px-6 py-4 sm:px-8" wire:key="edge-log-{{ $deployment->id }}">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-mono text-xs text-brand-ink">{{ $deployment->id }}</p>
                                <p class="mt-1 text-sm capitalize {{ $depBadge }}">{{ str_replace('_', ' ', (string) $deployment->status) }}</p>
                                <p class="mt-0.5 text-xs text-brand-moss">
                                    {{ $deployment->created_at?->timezone(config('app.timezone'))->format('M j, Y g:i A') ?? '—' }}
                                    @if ($deployment->git_commit)
                                        · <span class="font-mono">{{ \Illuminate\Support\Str::limit($deployment->git_commit, 8, '') }}</span>
                                    @endif
                                </p>
                            </div>
                            @if ($deployment->status === \App\Models\EdgeDeployment::STATUS_FAILED && is_string($failureReason) && $failureReason !== '')
                                <span class="rounded-full bg-rose-100 px-2 py-0.5 text-[10px] font-semibold uppercase text-rose-800 dark:bg-rose-950/40 dark:text-rose-300">{{ __('Failed') }}</span>
                            @endif
                        </div>
                        @if (is_string($failureReason) && $failureReason !== '')
                            @include('livewire.sites.partials.edge.build-log-lint-callout', [
                                'buildLog' => $buildLogLoaded ? $loadedBuildLog : null,
                                'failureReason' => $failureReason,
                                'site' => $site,
                                'server' => $server ?? $site->server,
                                'deployment' => $deployment,
                            ])
                            @if (! str_contains($failureReason, 'dply config lint failed'))
                                <pre class="mt-3 max-h-48 overflow-auto rounded-xl border border-rose-200/60 bg-rose-50/50 p-3 font-mono text-[11px] text-rose-900 dark:border-rose-900/30 dark:bg-rose-950/20 dark:text-rose-200">{{ $failureReason }}</pre>
                            @endif
                        @endif

                        @if ($deployment->build_log_path || (is_string($failureReason) && $failureReason !== ''))
                            <details
                                class="mt-3 rounded-xl border border-brand-ink/10 bg-brand-sand/15 dark:border-brand-mist/20 dark:bg-zinc-900/40"
                                x-on:toggle="if ($el.open) $wire.loadEdgeDeploymentBuildLog(@js($deployment->id))"
                            >
                                <summary class="cursor-pointer px-3 py-2 text-xs font-semibold text-brand-moss">{{ __('Build log') }}</summary>
                                <div class="border-t border-brand-ink/8 p-3">
                                    <div wire:loading wire:target="loadEdgeDeploymentBuildLog('{{ $deployment->id }}')" class="text-xs text-brand-moss">
                                        {{ __('Loading build log…') }}
                                    </div>
                                    @if ($buildLogLoaded)
                                        @if ($loadedBuildLog !== null && $loadedBuildLog !== '')
                                            @if ($deployment->status !== \App\Models\EdgeDeployment::STATUS_FAILED || ! is_string($failureReason) || $failureReason === '')
                                                @include('livewire.sites.partials.edge.build-log-lint-callout', [
                                                    'buildLog' => $loadedBuildLog,
                                                    'failureReason' => null,
                                                    'site' => $site,
                                                    'server' => $server ?? $site->server,
                                                    'deployment' => $deployment,
                                                ])
                                            @endif
                                            <pre class="max-h-64 overflow-auto font-mono text-[11px] text-brand-ink">{{ $loadedBuildLog }}</pre>
                                        @else
                                            <p class="text-xs text-brand-moss">{{ __('No build log stored for this deployment.') }}</p>
                                        @endif
                                    @endif
                                </div>
                            </details>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</section>

    <p class="text-right text-xs text-brand-moss">
        <a href="{{ route('sites.show', ['server' => $server ?? $site->server, 'site' => $site, 'section' => 'edge-deploys']) }}" wire:navigate class="font-semibold text-brand-sage hover:underline">
            {{ __('View full deploy history →') }}
        </a>
    </p>
</div>
