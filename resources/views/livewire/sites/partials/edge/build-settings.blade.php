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
        <p class="mt-0.5 text-sm text-brand-moss">{{ __('Command and output directory used on each deploy. Save here, then redeploy to apply.') }}</p>
    </div>
    @can('update', $site)
        <form wire:submit.prevent="saveEdgeBuildSettings" class="space-y-5 px-6 py-5 sm:px-8">
            <label class="block">
                <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Build command') }}</span>
                <input
                    type="text"
                    wire:model="edge_build_command"
                    autocomplete="off"
                    spellcheck="false"
                    class="mt-1.5 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900"
                />
                @error('edge_build_command')
                    <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
                @enderror
            </label>
            <label class="block">
                <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Output directory') }}</span>
                <input
                    type="text"
                    wire:model="edge_output_dir"
                    autocomplete="off"
                    spellcheck="false"
                    placeholder="dist"
                    class="mt-1.5 w-full max-w-xs rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900"
                />
                @error('edge_output_dir')
                    <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
                @enderror
            </label>
            <div class="space-y-3">
                <label class="flex items-start gap-3 text-sm text-brand-ink">
                    <input type="checkbox" wire:model="edge_spa_fallback" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage shadow-sm focus:ring-brand-sage/40" />
                    <span>
                        <span class="font-medium">{{ __('SPA fallback') }}</span>
                        <span class="mt-0.5 block text-xs text-brand-moss">{{ __('Unknown paths serve index.html after a 404.') }}</span>
                    </span>
                </label>
                <label class="flex items-start gap-3 text-sm text-brand-ink">
                    <input type="checkbox" wire:model="edge_deploy_on_push" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage shadow-sm focus:ring-brand-sage/40" />
                    <span>
                        <span class="font-medium">{{ __('Deploy on push') }}</span>
                        <span class="mt-0.5 block text-xs text-brand-moss">{{ __('Pushes to :branch trigger builds when GitHub auto-deploy is connected.', ['branch' => $edgeBranch]) }}</span>
                    </span>
                </label>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="saveEdgeBuildSettings"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-brand-ink/90"
                >
                    {{ __('Save build settings') }}
                </button>
                <p class="text-xs text-brand-moss">{{ __('Repository and branch stay fixed in v1 — create a new Edge site to change those.') }}</p>
            </div>
        </form>
    @else
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
                <dd class="min-w-0 flex-1 text-brand-ink">{{ $edgeSpaFallback ? __('Enabled') : __('Disabled') }}</dd>
            </div>
            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
                <dt class="w-36 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('Deploy on push') }}</dt>
                <dd class="min-w-0 flex-1 text-brand-ink">{{ $edgeDeployOnPush ? __('Yes') : __('No') }}</dd>
            </div>
        </dl>
    @endcan
</section>

<section class="dply-card overflow-hidden">
    <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
        <h3 class="text-base font-semibold text-brand-ink">{{ __('Retention') }}</h3>
        <p class="mt-0.5 text-sm text-brand-moss">
            {{ __('Older deployments beyond this count have their R2 artifacts deleted. Pruned deployments stay listed for audit but can\'t be rolled back without rebuilding from their commit.') }}
        </p>
    </div>
    <div class="px-6 py-5 sm:px-8">
        @can('update', $site)
            <form wire:submit.prevent="saveEdgeReleasesToKeep" class="flex flex-wrap items-end gap-3">
                <label class="block">
                    <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Releases to keep') }}</span>
                    <input
                        type="number"
                        min="1"
                        max="50"
                        wire:model="edge_releases_to_keep"
                        class="mt-1.5 w-24 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-ink focus:ring-1 focus:ring-brand-ink dark:border-brand-mist/20 dark:bg-zinc-900"
                    />
                </label>
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="saveEdgeReleasesToKeep"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40"
                >
                    {{ __('Save') }}
                </button>
                <span class="text-xs text-brand-moss">{{ __('Default: :default. Range 1–50.', ['default' => config('edge.retention.default_keep', 10)]) }}</span>
            </form>
        @else
            <p class="text-sm text-brand-ink">{{ __('Releases to keep: :count', ['count' => $edge_releases_to_keep]) }}</p>
        @endcan
    </div>
</section>

@if (! $edgeIsPreviewChild)
    <section class="dply-card overflow-hidden">
        <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
            <h3 class="text-base font-semibold text-brand-ink">{{ __('GitHub auto-deploy') }}</h3>
            <p class="mt-0.5 text-sm text-brand-moss">{{ __('Connect a linked GitHub account to register push and pull request webhooks automatically.') }}</p>
        </div>
        <div class="space-y-4 px-6 py-5 sm:px-8">
            <label class="block text-sm">
                <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Linked GitHub account') }}</span>
                <select
                    wire:model.live="edge_webhook_account_id"
                    class="mt-1.5 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-ink focus:ring-1 focus:ring-brand-ink dark:border-brand-mist/20 dark:bg-zinc-900"
                >
                    <option value="">{{ __('Select a linked GitHub account…') }}</option>
                    @foreach (($linkedSourceControlAccounts ?? []) as $account)
                        @if (($account['provider'] ?? '') === 'github')
                            <option value="{{ $account['id'] }}">{{ $account['label'] }}</option>
                        @endif
                    @endforeach
                </select>
            </label>

            <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-brand-ink/10 bg-brand-sand/20 px-4 py-3">
                <div>
                    <p class="text-sm font-semibold text-brand-ink">
                        {{ $edgeGithubWebhookConnected ? __('Auto-deploy is connected') : __('Auto-deploy is not connected') }}
                    </p>
                    <p class="mt-1 break-all font-mono text-[11px] text-brand-moss">{{ $site->edgeGithubHookUrl() }}</p>
                    @if ($edgeWebhookLastEventAt)
                        <p class="mt-1 text-xs text-brand-moss">{{ __('Last webhook event: :time', ['time' => $edgeWebhookLastEventAt]) }}</p>
                    @endif
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    @if ($edgeGithubWebhookConnected)
                        <button
                            type="button"
                            wire:click="disableEdgeGithubWebhook"
                            wire:loading.attr="disabled"
                            wire:target="disableEdgeGithubWebhook"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 bg-white px-3 py-1.5 text-xs font-semibold text-rose-900 shadow-sm hover:bg-rose-50 dark:border-rose-900/40 dark:bg-zinc-900 dark:text-rose-300"
                        >
                            <x-heroicon-o-x-mark class="h-3.5 w-3.5" />
                            {{ __('Disable') }}
                        </button>
                    @else
                        <button
                            type="button"
                            wire:click="enableEdgeGithubWebhook"
                            wire:loading.attr="disabled"
                            wire:target="enableEdgeGithubWebhook"
                            class="inline-flex items-center gap-2 rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60"
                        >
                            <x-heroicon-o-bolt class="h-3.5 w-3.5" />
                            {{ __('Enable auto-deploy') }}
                        </button>
                    @endif
                </div>
            </div>

            <details class="rounded-xl border border-brand-ink/10 bg-brand-sand/10 px-4 py-3 text-sm dark:border-brand-mist/20 dark:bg-zinc-900/40">
                <summary class="cursor-pointer font-medium text-brand-ink">{{ __('Manual webhook setup') }}</summary>
                <div class="mt-3 space-y-3" x-data="{ copiedHook: false, copiedSecret: false }">
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
                    @if ($site->webhook_secret)
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Webhook secret') }}</p>
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                <input type="password" readonly value="{{ $site->webhook_secret }}" class="block min-w-0 flex-1 rounded-xl border border-brand-ink/15 bg-brand-sand/20 px-3 py-2 font-mono text-xs text-brand-ink" onclick="this.select()" />
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/10 bg-white px-3 py-2 text-xs font-medium text-brand-moss hover:bg-brand-sand/40"
                                    @click="navigator.clipboard.writeText(@js($site->webhook_secret)); copiedSecret = true; setTimeout(() => copiedSecret = false, 2000)"
                                >
                                    <x-heroicon-o-clipboard class="h-4 w-4" />
                                    <span x-show="!copiedSecret">{{ __('Copy') }}</span>
                                    <span x-show="copiedSecret" x-cloak>{{ __('Copied') }}</span>
                                </button>
                            </div>
                        </div>
                    @endif
                </div>
            </details>
        </div>
    </section>
@endif

@if (($edgeRuntimeMode ?? 'static') === 'hybrid' && is_array($edgeOrigin ?? null))
    <section class="dply-card overflow-hidden">
        <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
            <h3 class="text-base font-semibold text-brand-ink">{{ __('SSR origin (hybrid)') }}</h3>
            <p class="mt-0.5 text-sm text-brand-moss">{{ __('Static assets are served from Edge; dynamic routes proxy to this origin after an R2 miss.') }}</p>
        </div>
        <dl class="divide-y divide-brand-ink/8 px-6 py-2 text-sm sm:px-8">
            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
                <dt class="w-36 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('Origin URL') }}</dt>
                <dd class="min-w-0 flex-1 font-mono text-xs text-brand-ink break-all">{{ $edgeOrigin['url'] ?? '—' }}</dd>
            </div>
            @if (! empty($edgeOrigin['routes']))
                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
                    <dt class="w-36 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('Proxy routes') }}</dt>
                    <dd class="min-w-0 flex-1 font-mono text-xs text-brand-ink">{{ implode(', ', $edgeOrigin['routes']) }}</dd>
                </div>
            @endif
        </dl>
    </section>
@endif
