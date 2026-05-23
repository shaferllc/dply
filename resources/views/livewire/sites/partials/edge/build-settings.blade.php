<section class="dply-card overflow-hidden">
    <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
        <h3 class="text-base font-semibold text-brand-ink">{{ __('Edge delivery') }}</h3>
        <p class="mt-0.5 text-sm text-brand-moss">{{ __('Where builds are published after each deploy.') }}</p>
    </div>
    <dl class="divide-y divide-brand-ink/8 px-6 py-2 text-sm sm:px-8">
        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
            <dt class="w-36 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('Mode') }}</dt>
            <dd class="min-w-0 flex-1 text-brand-ink">{{ $edgeDeliveryBackendLabel ?? $site->edgeBackendLabel() }}</dd>
        </div>
        @if ($edgeUsesManagedBackend ?? $site->edge_backend === 'dply_edge')
            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
                <dt class="w-36 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('Publish hostname') }}</dt>
                <dd class="min-w-0 flex-1 font-mono text-xs text-brand-ink break-all">{{ $edgeDeliveryHostname ?? $site->edgeHostname() }}</dd>
            </div>
            @if (! empty($edgeWorkerZoneName))
                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
                    <dt class="w-36 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('Worker zone') }}</dt>
                    <dd class="min-w-0 flex-1 font-mono text-xs text-brand-ink">{{ $edgeWorkerZoneName }}</dd>
                </div>
            @endif
        @endif
        @if ($site->usesOrgCloudflareEdge() && $site->edgeProviderCredential)
            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
                <dt class="w-36 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('Cloudflare account') }}</dt>
                <dd class="min-w-0 flex-1 text-brand-ink">{{ $site->edgeProviderCredential->name }}</dd>
            </div>
        @endif
    </dl>
    <div class="border-t border-brand-ink/10 bg-brand-sand/15 px-6 py-3 text-xs text-brand-moss sm:px-8">
        {{ __('Delivery backend is fixed after the first publish in v1. Create a new Edge site to switch between managed and BYO Cloudflare.') }}
    </div>
</section>

<section class="dply-card overflow-hidden">
    <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
        <h3 class="text-base font-semibold text-brand-ink">{{ __('Repository & branch') }}</h3>
        <p class="mt-0.5 text-sm text-brand-moss">{{ __('Source control settings from site creation. Changing these requires a new Edge site in v1.') }}</p>
    </div>
    <dl class="divide-y divide-brand-ink/8 px-6 py-2 text-sm sm:px-8">
        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
            <dt class="w-36 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('Repository') }}</dt>
            <dd class="min-w-0 flex-1 font-mono text-xs text-brand-ink break-all">
                @if ($edgeGithubRepoUrl)
                    <a href="{{ $edgeGithubRepoUrl }}" target="_blank" rel="noopener noreferrer" class="text-brand-forest hover:underline dark:text-brand-sage">{{ $edgeRepo }}</a>
                @else
                    {{ $edgeRepo ?: '—' }}
                @endif
            </dd>
        </div>
        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
            <dt class="w-36 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('Production branch') }}</dt>
            <dd class="min-w-0 flex-1 font-mono text-xs text-brand-ink">{{ $edgeBranch }}</dd>
        </div>
    </dl>
</section>

<section class="dply-card overflow-hidden">
    <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
        <h3 class="text-base font-semibold text-brand-ink">{{ __('Build configuration') }}</h3>
        <p class="mt-0.5 text-sm text-brand-moss">{{ __('Command and output directory used on each deploy.') }}</p>
    </div>
    <dl class="divide-y divide-brand-ink/8 px-6 py-2 text-sm sm:px-8">
        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
            <dt class="w-36 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('Build command') }}</dt>
            <dd class="min-w-0 flex-1 font-mono text-xs text-brand-ink break-all">{{ $edgeBuildCommand }}</dd>
        </div>
        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
            <dt class="w-36 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('Output directory') }}</dt>
            <dd class="min-w-0 flex-1 font-mono text-xs text-brand-ink">{{ $edgeOutputDir }}</dd>
        </div>
        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
            <dt class="w-36 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('SPA fallback') }}</dt>
            <dd class="min-w-0 flex-1 text-brand-ink">{{ $edgeSpaFallback ? __('Enabled — unknown paths serve index.html') : __('Disabled') }}</dd>
        </div>
        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
            <dt class="w-36 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('Deploy on push') }}</dt>
            <dd class="min-w-0 flex-1 text-brand-ink">{{ $edgeDeployOnPush ? __('Yes — pushes to :branch trigger builds', ['branch' => $edgeBranch]) : __('No — manual redeploy only') }}</dd>
        </div>
    </dl>
</section>

@if (! $edgeIsPreviewChild)
    <section class="dply-card overflow-hidden" x-data="{ copiedHook: false, copiedSecret: false }">
        <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
            <h3 class="text-base font-semibold text-brand-ink">{{ __('GitHub webhook') }}</h3>
            <p class="mt-0.5 text-sm text-brand-moss">{{ __('Add this URL to your repository webhooks for push events and pull request previews.') }}</p>
        </div>
        <div class="space-y-4 px-6 py-5 sm:px-8">
            <div>
                <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Payload URL') }}</p>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <input type="text" readonly value="{{ $site->edgeGithubHookUrl() }}" class="block min-w-0 flex-1 rounded-xl border border-brand-ink/15 bg-brand-sand/20 px-3 py-2 font-mono text-xs text-brand-ink" onclick="this.select()" />
                    <button
                        type="button"
                        class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/10 bg-white px-3 py-2 text-xs font-medium text-brand-moss hover:bg-brand-sand/40"
                        @click="navigator.clipboard.writeText(@js($site->edgeGithubHookUrl())); copiedHook = true; setTimeout(() => copiedHook = false, 2000)"
                    >
                        <x-heroicon-o-clipboard class="h-4 w-4" />
                        <span x-show="!copiedHook">{{ __('Copy') }}</span>
                        <span x-show="copiedHook" x-cloak>{{ __('Copied') }}</span>
                    </button>
                </div>
            </div>
            @if (! empty($edgeMeta['webhook_secret']))
                <div>
                    <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Webhook secret') }}</p>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <input type="password" readonly value="{{ $edgeMeta['webhook_secret'] }}" class="block min-w-0 flex-1 rounded-xl border border-brand-ink/15 bg-brand-sand/20 px-3 py-2 font-mono text-xs text-brand-ink" onclick="this.select()" />
                        <button
                            type="button"
                            class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/10 bg-white px-3 py-2 text-xs font-medium text-brand-moss hover:bg-brand-sand/40"
                            @click="navigator.clipboard.writeText(@js($edgeMeta['webhook_secret'])); copiedSecret = true; setTimeout(() => copiedSecret = false, 2000)"
                        >
                            <x-heroicon-o-clipboard class="h-4 w-4" />
                            <span x-show="!copiedSecret">{{ __('Copy') }}</span>
                            <span x-show="copiedSecret" x-cloak>{{ __('Copied') }}</span>
                        </button>
                    </div>
                </div>
            @endif
        </div>
    </section>
@endif
