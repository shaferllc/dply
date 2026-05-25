@php
    use App\Models\EdgeDeployment;

    $edgeBuildRepoConfig = null;
    if ($site->relationLoaded('edgeDeployments') && $site->edgeDeployments !== null) {
        $deploymentsWithConfig = $site->edgeDeployments->filter(
            fn (EdgeDeployment $deployment): bool => is_array($deployment->repo_config) && $deployment->repo_config !== [],
        );
        $edgeBuildRepoConfig = $deploymentsWithConfig
            ->first(fn (EdgeDeployment $deployment): bool => $deployment->status === EdgeDeployment::STATUS_LIVE)
            ?->repo_config
            ?? $deploymentsWithConfig->first()?->repo_config;
    }
@endphp

@include('livewire.sites.partials.edge.build-settings-nav')

<section id="edge-build-delivery" class="scroll-mt-24 dply-card overflow-hidden">
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
        @if (($edgeRepoRoot ?? $site->edgeRepoRoot()) !== '')
            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
                <dt class="w-36 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('Repository root') }}</dt>
                <dd class="min-w-0 flex-1 font-mono text-xs text-brand-ink">{{ $edgeRepoRoot ?? $site->edgeRepoRoot() }}</dd>
            </div>
        @endif
    </dl>
</section>

@php
    $latestRepoConfig = $edgeBuildRepoConfig;
@endphp

@if ($latestRepoConfig !== null)
    <section id="edge-build-repo-config" class="scroll-mt-24 dply-card overflow-hidden">
        <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
                <div>
                    <h3 class="inline-flex items-center gap-2 text-base font-semibold text-brand-ink">
                        <x-heroicon-o-document-text class="h-4 w-4 text-brand-forest dark:text-brand-sage" aria-hidden="true" />
                        {{ __('Managed by :file', ['file' => $latestRepoConfig['source_path'] ?? 'dply.yaml']) }}
                    </h3>
                    <p class="mt-0.5 text-sm text-brand-moss">{{ __('Build, redirects, rewrites, and header rules from the repo override the dashboard settings below on each deploy.') }}</p>
                </div>
                <span class="inline-flex items-center gap-1 rounded-full bg-brand-sand/60 px-2 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-wide text-brand-moss">
                    {{ __('Repo config') }}
                </span>
            </div>
        </div>
        <dl class="grid grid-cols-2 gap-y-3 gap-x-6 px-6 py-4 text-sm sm:grid-cols-4 sm:px-8">
            <div>
                <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Build overrides') }}</dt>
                <dd class="mt-0.5 font-mono text-xs text-brand-ink">{{ empty($latestRepoConfig['build']) ? __('—') : count($latestRepoConfig['build']).' '.__('keys') }}</dd>
            </div>
            <div>
                <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Redirects') }}</dt>
                <dd class="mt-0.5 font-mono text-xs text-brand-ink">{{ count((array) ($latestRepoConfig['redirects'] ?? [])) }}</dd>
            </div>
            <div>
                <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Rewrites') }}</dt>
                <dd class="mt-0.5 font-mono text-xs text-brand-ink">{{ count((array) ($latestRepoConfig['rewrites'] ?? [])) }}</dd>
            </div>
            <div>
                <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Header rules') }}</dt>
                <dd class="mt-0.5 font-mono text-xs text-brand-ink">{{ count((array) ($latestRepoConfig['headers'] ?? [])) }}</dd>
            </div>
        </dl>
        @php
            $latestBindings = is_array($latestRepoConfig['bindings'] ?? null) ? $latestRepoConfig['bindings'] : [];
        @endphp
        @if ($latestBindings !== [])
            <div class="border-t border-brand-ink/10 px-6 py-3 text-xs text-brand-moss sm:px-8">
                <p class="font-semibold uppercase tracking-wide text-brand-mist">{{ __('Bindings exposed to middleware / SSR') }}</p>
                <div class="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-2">
                    @foreach (['kv' => __('KV namespaces'), 'r2' => __('R2 buckets'), 'd1' => __('D1 databases'), 'queues' => __('Queues')] as $kind => $label)
                        @php
                            $items = is_array($latestBindings[$kind] ?? null) ? $latestBindings[$kind] : [];
                        @endphp
                        @if ($items !== [])
                            <div>
                                <p class="font-semibold uppercase tracking-wide text-brand-mist">{{ $label }}</p>
                                <ul class="mt-1 space-y-0.5 font-mono">
                                    @foreach ($items as $bindingName => $targetId)
                                        <li><span class="text-brand-ink">{{ $bindingName }}</span> <span class="text-brand-mist">→</span> {{ \Illuminate\Support\Str::limit((string) $targetId, 32) }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>
        @endif
        @if (! empty($latestRepoConfig['warnings']))
            <div class="border-t border-amber-300/60 bg-amber-50 px-6 py-3 text-xs text-amber-900 dark:bg-amber-950/40 dark:text-amber-200 sm:px-8">
                <p class="font-semibold uppercase tracking-wide">{{ __('Warnings from the last parse') }}</p>
                <ul class="mt-1 list-disc space-y-0.5 pl-4">
                    @foreach ((array) $latestRepoConfig['warnings'] as $warning)
                        <li class="font-mono">{{ $warning }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
    </section>
@endif

@include('livewire.sites.partials.edge.repo-routing-rules')

<section id="edge-build-configuration" class="scroll-mt-24 dply-card overflow-hidden">
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
            <label class="block">
                <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Repository root') }}</span>
                <input
                    type="text"
                    wire:model="edge_repo_root"
                    autocomplete="off"
                    spellcheck="false"
                    placeholder="apps/web"
                    class="mt-1.5 w-full max-w-md rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900"
                />
                <p class="mt-1 text-xs text-brand-moss">{{ __('Optional monorepo subdirectory. Builds run from this folder; GitHub auto-deploy only triggers when changed files touch this path or dply.toml/yaml at the repo root.') }}</p>
                @error('edge_repo_root')
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
                    class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60"
                >
                    <x-spinner variant="white" size="sm" wire:loading wire:target="saveEdgeBuildSettings" />
                    <span wire:loading.remove wire:target="saveEdgeBuildSettings">{{ __('Save build settings') }}</span>
                    <span wire:loading wire:target="saveEdgeBuildSettings">{{ __('Saving…') }}</span>
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

@if (! $site->isEdgePreview())
    <section class="dply-card overflow-hidden">
        <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
            <h3 class="text-base font-semibold text-brand-ink">{{ __('Deploy hooks') }}</h3>
            <p class="mt-0.5 text-sm text-brand-moss">{{ __('Per-site URLs that trigger a redeploy when POSTed. Hand the URL to your CMS (Sanity, Contentful, Strapi) so a content change publishes automatically.') }}</p>
        </div>

        @if ($edge_just_minted_deploy_hook_url !== null)
            <div class="border-b border-emerald-300/60 bg-emerald-50 px-6 py-4 text-sm text-emerald-950 dark:border-emerald-900/40 dark:bg-emerald-950/30 dark:text-emerald-100 sm:px-8">
                <p class="font-semibold">{{ __('Copy your hook URL now — dply won\'t show it again') }}</p>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <code class="flex-1 min-w-0 break-all rounded-lg bg-white px-3 py-2 font-mono text-[11px] text-brand-ink shadow-sm dark:bg-zinc-900">{{ $edge_just_minted_deploy_hook_url }}</code>
                    <button type="button" wire:click="dismissEdgeDeployHookUrl" class="text-[11px] font-semibold text-emerald-900 hover:underline dark:text-emerald-200">{{ __('Got it') }}</button>
                </div>
            </div>
        @endif

        @can('update', $site)
            <form wire:submit.prevent="mintEdgeDeployHook" class="flex flex-wrap items-end gap-2 border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                <label class="flex-1 min-w-[14rem]">
                    <span class="block text-[11px] font-semibold uppercase tracking-[0.12em] text-brand-mist">{{ __('Hook name') }}</span>
                    <input type="text"
                           wire:model="edge_new_deploy_hook_name"
                           placeholder="Sanity prod publish"
                           class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900" />
                </label>
                <button type="submit" wire:loading.attr="disabled" wire:target="mintEdgeDeployHook" class="rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60">
                    {{ __('Create hook') }}
                </button>
            </form>
        @endcan

        @php
            $hooks = $this->edgeDeployHooks();
        @endphp
        @if ($hooks->isEmpty())
            <div class="px-6 py-6 text-center text-xs text-brand-moss sm:px-8">{{ __('No deploy hooks yet.') }}</div>
        @else
            <ul class="divide-y divide-brand-ink/8">
                @foreach ($hooks as $hook)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-6 py-3 sm:px-8" wire:key="edge-hook-{{ $hook->id }}">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-brand-ink">{{ $hook->name }}</p>
                            <p class="mt-0.5 font-mono text-[11px] text-brand-moss">
                                {{ __('Token starts with') }} <span class="rounded-md bg-brand-sand/40 px-1.5 py-0.5 text-brand-ink">{{ $hook->token_prefix }}…</span>
                                @if ($hook->last_used_at)
                                    · {{ __('last fired :when', ['when' => $hook->last_used_at->diffForHumans()]) }}
                                @else
                                    · {{ __('never fired') }}
                                @endif
                            </p>
                        </div>
                        @can('update', $site)
                            <button
                                type="button"
                                wire:click="revokeEdgeDeployHook('{{ $hook->id }}')"
                                wire:confirm="{{ __('Revoke this deploy hook? The URL will stop working immediately.') }}"
                                class="text-xs font-medium text-rose-700 hover:text-rose-900 dark:text-rose-400">
                                {{ __('Revoke') }}
                            </button>
                        @endcan
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
@endif

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
                    class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40 disabled:cursor-wait disabled:opacity-60"
                >
                    <x-spinner variant="ink" size="sm" wire:loading wire:target="saveEdgeReleasesToKeep" />
                    <span wire:loading.remove wire:target="saveEdgeReleasesToKeep">{{ __('Save') }}</span>
                    <span wire:loading wire:target="saveEdgeReleasesToKeep">{{ __('Saving…') }}</span>
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
            <p class="mt-0.5 text-sm text-brand-moss">{{ __('Connect a linked GitHub account to register push and pull request webhooks automatically. Pull requests get a Check Run and a single summary comment (updated in place on each push) with the preview URL when the deploy lands.') }}</p>
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

@if (($edgeRuntimeMode ?? 'static') !== 'hybrid')
    @can('update', $site)
        <section class="dply-card overflow-hidden">
            <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                <h3 class="text-base font-semibold text-brand-ink">{{ __('Convert to hybrid SSR') }}</h3>
                <p class="mt-0.5 text-sm text-brand-moss">{{ __('Point this Edge site at an existing origin server (a dply Cloud container, your own VM, etc.) so dynamic routes proxy through. Static assets keep serving from R2.') }}</p>
            </div>
            <form wire:submit.prevent="convertEdgeStaticToHybrid" class="space-y-4 px-6 py-5 sm:px-8">
                <label class="block">
                    <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Existing origin URL') }}</span>
                    <input
                        type="url"
                        wire:model="edge_convert_origin_url"
                        autocomplete="off"
                        spellcheck="false"
                        placeholder="https://my-origin.dply.app"
                        class="mt-1.5 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900"
                    />
                    <p class="mt-1 text-xs text-brand-moss">{{ __('Default proxy routes: /api/*, /_next/data/*. Edit them after conversion.') }}</p>
                    @error('edge_convert_origin_url')
                        <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
                    @enderror
                </label>
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="convertEdgeStaticToHybrid"
                    wire:confirm="{{ __('Convert this site to hybrid mode? The next deploy will run the origin healthcheck before going LIVE.') }}"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60"
                >
                    <x-spinner variant="white" size="sm" wire:loading wire:target="convertEdgeStaticToHybrid" />
                    <span wire:loading.remove wire:target="convertEdgeStaticToHybrid">{{ __('Convert to hybrid') }}</span>
                    <span wire:loading wire:target="convertEdgeStaticToHybrid">{{ __('Converting…') }}</span>
                </button>
            </form>
        </section>
    @endcan
@endif

@if (($edgeRuntimeMode ?? 'static') === 'hybrid' && is_array($edgeOrigin ?? null))
    <section class="dply-card overflow-hidden">
        <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
            <h3 class="text-base font-semibold text-brand-ink">{{ __('SSR origin (hybrid)') }}</h3>
            <p class="mt-0.5 text-sm text-brand-moss">{{ __('Static assets are served from Edge; dynamic routes proxy to this origin after an R2 miss. Saved changes take effect immediately — the Worker host map is republished on save.') }}</p>
        </div>
        @can('update', $site)
            <form wire:submit.prevent="saveEdgeHybridOrigin" class="space-y-5 px-6 py-5 sm:px-8">
                <label class="block">
                    <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Origin URL') }}</span>
                    <input
                        type="url"
                        wire:model="edge_origin_url"
                        autocomplete="off"
                        spellcheck="false"
                        placeholder="https://my-origin.dply.app"
                        class="mt-1.5 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900"
                    />
                    @error('edge_origin_url')
                        <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
                    @enderror
                    @if (! empty($edgeOrigin['managed']))
                        <p class="mt-1 text-xs text-brand-moss">{{ __('This origin was provisioned by dply (managed). You can still point at a different URL if you have one ready.') }}</p>
                    @endif
                </label>
                <label class="block">
                    <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Proxy routes') }}</span>
                    <textarea
                        wire:model="edge_origin_routes"
                        rows="5"
                        spellcheck="false"
                        placeholder="/api/*&#10;/_next/data/*"
                        class="mt-1.5 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900"
                    ></textarea>
                    <p class="mt-1 text-xs text-brand-moss">{{ __('One pattern per line. Use a leading / and * as a wildcard, e.g. /api/* or /_next/data/*.') }}</p>
                    @error('edge_origin_routes')
                        <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
                    @enderror
                </label>
                <label class="block">
                    <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Healthcheck path') }}</span>
                    <input
                        type="text"
                        wire:model="edge_origin_healthcheck_path"
                        autocomplete="off"
                        spellcheck="false"
                        placeholder="/"
                        class="mt-1.5 w-full max-w-xs rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900"
                    />
                    <p class="mt-1 text-xs text-brand-moss">{{ __('GET this path on the origin before flipping Edge LIVE. 2xx/3xx pass; anything else fails the deploy.') }}</p>
                    @error('edge_origin_healthcheck_path')
                        <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
                    @enderror
                </label>
                <label class="block">
                    <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Failover HTML (optional)') }}</span>
                    <textarea
                        wire:model="edge_origin_failover_html"
                        rows="6"
                        spellcheck="false"
                        placeholder="{{ __('Leave blank to use the built-in dply 503 page.') }}"
                        class="mt-1.5 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900"
                    ></textarea>
                    <p class="mt-1 text-xs text-brand-moss">{{ __('Shown when the origin returns 5xx or times out (after one auto-retry). Limit 32 KB. Served as HTTP 503 with Retry-After: 30.') }}</p>
                    @error('edge_origin_failover_html')
                        <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
                    @enderror
                </label>
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="saveEdgeHybridOrigin"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60"
                >
                    <x-spinner variant="white" size="sm" wire:loading wire:target="saveEdgeHybridOrigin" />
                    <span wire:loading.remove wire:target="saveEdgeHybridOrigin">{{ __('Save origin settings') }}</span>
                    <span wire:loading wire:target="saveEdgeHybridOrigin">{{ __('Saving…') }}</span>
                </button>
            </form>

            @can('update', $site)
                <div class="border-t border-brand-ink/10 px-6 py-5 sm:px-8">
                    <p class="text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Purge edge cache by tag') }}</p>
                    <p class="mt-1 text-xs text-brand-moss">{{ __('When your origin sets `Cache-Tag: foo,bar` or `X-Dply-Cache-Tag: foo,bar` on a cacheable response, the Worker indexes entries by tag. Purging here drops the indexed entries via Cloudflare KV (takes effect within ~60s of KV propagation). Use `X-Dply-Cache-Tag` if your origin sits behind Cloudflare and `Cache-Tag` never reaches the Worker.') }}</p>
                    <form wire:submit.prevent="purgeEdgeCacheByTag" class="mt-2 flex flex-wrap items-center gap-2">
                        <input
                            type="text"
                            wire:model="edge_cache_purge_tag"
                            autocomplete="off"
                            spellcheck="false"
                            placeholder="article-42"
                            class="min-w-0 flex-1 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900"
                        />
                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            wire:target="purgeEdgeCacheByTag"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40 disabled:cursor-wait disabled:opacity-60"
                        >
                            <x-heroicon-o-trash class="h-3.5 w-3.5" wire:loading.remove wire:target="purgeEdgeCacheByTag" />
                            <x-spinner variant="ink" size="sm" wire:loading wire:target="purgeEdgeCacheByTag" />
                            <span wire:loading.remove wire:target="purgeEdgeCacheByTag">{{ __('Purge') }}</span>
                            <span wire:loading wire:target="purgeEdgeCacheByTag">{{ __('Purging…') }}</span>
                        </button>
                    </form>
                    @error('edge_cache_purge_tag')
                        <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
                    @enderror
                </div>
            @endcan

            @if (! empty($edgeOrigin['auth_secret']))
                <div class="border-t border-brand-ink/10 px-6 py-5 sm:px-8" x-data="{ copied: false }">
                    <p class="text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Origin auth secret') }}</p>
                    <p class="mt-1 text-xs text-brand-moss">{{ __('Worker attaches this as `X-Dply-Origin-Auth` on every proxied request. Have your origin app reject requests without a matching value so direct origin-URL traffic returns 401 / 403.') }}</p>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <input
                            type="password"
                            readonly
                            value="{{ $edgeOrigin['auth_secret'] }}"
                            class="block min-w-0 flex-1 rounded-xl border border-brand-ink/15 bg-brand-sand/20 px-3 py-2 font-mono text-xs text-brand-ink"
                            onclick="this.select()"
                        />
                        <button
                            type="button"
                            class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/10 bg-white px-3 py-2 text-xs font-medium text-brand-moss hover:bg-brand-sand/40"
                            @click="navigator.clipboard.writeText(@js($edgeOrigin['auth_secret'])); copied = true; setTimeout(() => copied = false, 2000)"
                        >
                            <x-heroicon-o-clipboard class="h-4 w-4" />
                            <span x-show="!copied">{{ __('Copy') }}</span>
                            <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                        </button>
                        <button
                            type="button"
                            wire:click="rotateEdgeHybridOriginSecret"
                            wire:loading.attr="disabled"
                            wire:target="rotateEdgeHybridOriginSecret"
                            wire:confirm="{{ __('Rotate the origin auth secret? Requests using the old value will fail at the origin until you update it there.') }}"
                            class="inline-flex items-center gap-1 rounded-lg border border-rose-200 bg-white px-3 py-2 text-xs font-semibold text-rose-900 shadow-sm hover:bg-rose-50 dark:border-rose-900/40 dark:bg-zinc-900 dark:text-rose-300"
                        >
                            <x-heroicon-o-arrow-path class="h-3.5 w-3.5" />
                            {{ __('Rotate') }}
                        </button>
                    </div>
                </div>
            @endif
        @else
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
        @endcan
    </section>
@endif

@can('update', $site)
    @if (! $edgeIsPreviewChild)
        <section class="dply-card overflow-hidden">
            <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                <h3 class="text-base font-semibold text-brand-ink">{{ __('Preview comment widget') }}</h3>
                <p class="mt-0.5 text-sm text-brand-moss">{{ __('Show a floating "Comments" button on every preview deploy of this site. Anyone visiting the preview URL can leave anonymous review notes that appear in the preview workspace.') }}</p>
            </div>
            <form wire:submit.prevent="saveEdgeCommentWidget" class="space-y-3 px-6 py-5 sm:px-8">
                <label class="flex items-start gap-3 text-sm text-brand-ink">
                    <input type="checkbox" wire:model="edge_comment_widget_enabled" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage shadow-sm focus:ring-brand-sage/40" />
                    <span>
                        <span class="font-medium">{{ __('Inject widget on preview deploys') }}</span>
                        <span class="mt-0.5 block text-xs text-brand-moss">{{ __('Worker adds a script tag before </body> on HTML responses for any PR preview of this site. Production traffic is never touched.') }}</span>
                    </span>
                </label>
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="saveEdgeCommentWidget"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60"
                >
                    <x-spinner variant="white" size="sm" wire:loading wire:target="saveEdgeCommentWidget" />
                    <span wire:loading.remove wire:target="saveEdgeCommentWidget">{{ __('Save widget settings') }}</span>
                    <span wire:loading wire:target="saveEdgeCommentWidget">{{ __('Saving…') }}</span>
                </button>
            </form>
        </section>

        <section id="edge-build-preview-protection" class="scroll-mt-24 dply-card overflow-hidden">
            <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                <h3 class="text-base font-semibold text-brand-ink">{{ __('Preview protection') }}</h3>
                <p class="mt-0.5 text-sm text-brand-moss">{{ __('Require a password or Dply sign-in before anyone can view PR previews and deploy aliases. Your live production URL and custom domains stay public.') }}</p>
            </div>
            <form wire:submit.prevent="saveEdgePreviewProtection" class="space-y-5 px-6 py-5 sm:px-8">
                <fieldset class="space-y-3">
                    <legend class="sr-only">{{ __('Preview protection mode') }}</legend>
                    <label class="flex items-start gap-3 text-sm text-brand-ink">
                        <input type="radio" wire:model="edge_preview_protection_mode" value="off" class="mt-0.5 border-brand-ink/20 text-brand-sage focus:ring-brand-sage/40" />
                        <span>
                            <span class="font-medium">{{ __('Off') }}</span>
                            <span class="mt-0.5 block text-xs text-brand-moss">{{ __('Anyone with a preview or alias URL can view the deploy.') }}</span>
                        </span>
                    </label>
                    <label class="flex items-start gap-3 text-sm text-brand-ink">
                        <input type="radio" wire:model="edge_preview_protection_mode" value="password" class="mt-0.5 border-brand-ink/20 text-brand-sage focus:ring-brand-sage/40" />
                        <span>
                            <span class="font-medium">{{ __('Shared password') }}</span>
                            <span class="mt-0.5 block text-xs text-brand-moss">{{ __('Visitors enter one site-wide password at the edge before the preview loads.') }}</span>
                        </span>
                    </label>
                    <label class="flex items-start gap-3 text-sm text-brand-ink">
                        <input type="radio" wire:model="edge_preview_protection_mode" value="dply_account" class="mt-0.5 border-brand-ink/20 text-brand-sage focus:ring-brand-sage/40" />
                        <span>
                            <span class="font-medium">{{ __('Dply account') }}</span>
                            <span class="mt-0.5 block text-xs text-brand-moss">{{ __('Visitors sign in to Dply; optionally restrict to specific email addresses.') }}</span>
                        </span>
                    </label>
                    @error('edge_preview_protection_mode')
                        <p class="text-xs text-rose-700">{{ $message }}</p>
                    @enderror
                </fieldset>

                @if ($edge_preview_protection_mode === 'password')
                    <label class="block">
                        <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Preview password') }}</span>
                        <input
                            type="password"
                            wire:model="edge_preview_protection_password"
                            autocomplete="new-password"
                            placeholder="{{ __('Leave blank to keep the current password') }}"
                            class="mt-1.5 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900"
                        />
                        <p class="mt-1 text-xs text-brand-moss">{{ __('Required when enabling password protection for the first time. Changing the password invalidates existing preview access cookies.') }}</p>
                        @error('edge_preview_protection_password')
                            <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
                        @enderror
                    </label>
                @endif

                @if ($edge_preview_protection_mode === 'dply_account')
                    <label class="block">
                        <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Allowed email addresses') }}</span>
                        <textarea
                            wire:model="edge_preview_protection_allowed_emails"
                            rows="4"
                            spellcheck="false"
                            placeholder="reviewer@example.com&#10;pm@example.com"
                            class="mt-1.5 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900"
                        ></textarea>
                        <p class="mt-1 text-xs text-brand-moss">{{ __('Optional. One email per line (commas also work). Leave empty to allow any signed-in Dply user who can view this site.') }}</p>
                        @error('edge_preview_protection_allowed_emails')
                            <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
                        @enderror
                    </label>
                @endif

                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="saveEdgePreviewProtection"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60"
                >
                    <x-spinner variant="white" size="sm" wire:loading wire:target="saveEdgePreviewProtection" />
                    <span wire:loading.remove wire:target="saveEdgePreviewProtection">{{ __('Save preview protection') }}</span>
                    <span wire:loading wire:target="saveEdgePreviewProtection">{{ __('Saving…') }}</span>
                </button>
            </form>
        </section>
    @endif

    @php
        $imagesMeta = is_array($edgeMeta['images'] ?? null) ? $edgeMeta['images'] : [];
        $imageSecret = is_string($imagesMeta['signing_secret'] ?? null) ? (string) $imagesMeta['signing_secret'] : '';
    @endphp
    <section class="dply-card overflow-hidden">
        <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
            <h3 class="text-base font-semibold text-brand-ink">{{ __('Image optimization') }}</h3>
            <p class="mt-0.5 text-sm text-brand-moss">{{ __('Resize and reformat images at the edge via Cloudflare Image Resizing. Generate signed URLs server-side with App\\Services\\Edge\\EdgeImageUrlSigner.') }}</p>
        </div>
        <form wire:submit.prevent="saveEdgeImageOptimization" class="space-y-5 px-6 py-5 sm:px-8">
            <label class="flex items-start gap-3 text-sm text-brand-ink">
                <input type="checkbox" wire:model="edge_image_optimization_enabled" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage shadow-sm focus:ring-brand-sage/40" />
                <span>
                    <span class="font-medium">{{ __('Enable image optimization') }}</span>
                    <span class="mt-0.5 block text-xs text-brand-moss">{{ __('Adds the /_dply/image route on this site\'s edge hostname. Requires Cloudflare Image Resizing on the zone.') }}</span>
                </span>
            </label>

            <label class="block">
                <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Allowed source hostnames') }}</span>
                <textarea
                    wire:model="edge_image_allowed_hosts"
                    rows="4"
                    spellcheck="false"
                    placeholder="images.example.com&#10;cdn.example.org"
                    class="mt-1.5 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900"
                ></textarea>
                <p class="mt-1 text-xs text-brand-moss">{{ __('One hostname per line. Only listed hosts may be used as ?url=… sources; otherwise the optimizer would proxy arbitrary images.') }}</p>
                @error('edge_image_allowed_hosts')
                    <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
                @enderror
            </label>

            <button
                type="submit"
                wire:loading.attr="disabled"
                wire:target="saveEdgeImageOptimization"
                class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60"
            >
                <x-spinner variant="white" size="sm" wire:loading wire:target="saveEdgeImageOptimization" />
                <span wire:loading.remove wire:target="saveEdgeImageOptimization">{{ __('Save image settings') }}</span>
                <span wire:loading wire:target="saveEdgeImageOptimization">{{ __('Saving…') }}</span>
            </button>
        </form>

        @if ($imageSecret !== '')
            <div class="border-t border-brand-ink/10 px-6 py-5 sm:px-8" x-data="{ copiedSig: false }">
                <p class="text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Image signing secret') }}</p>
                <p class="mt-1 text-xs text-brand-moss">{{ __('Used to HMAC-sign /_dply/image URLs. Anyone with this secret can mint valid image URLs against your allowed source hosts.') }}</p>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <input
                        type="password"
                        readonly
                        value="{{ $imageSecret }}"
                        class="block min-w-0 flex-1 rounded-xl border border-brand-ink/15 bg-brand-sand/20 px-3 py-2 font-mono text-xs text-brand-ink"
                        onclick="this.select()"
                    />
                    <button
                        type="button"
                        class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/10 bg-white px-3 py-2 text-xs font-medium text-brand-moss hover:bg-brand-sand/40"
                        @click="navigator.clipboard.writeText(@js($imageSecret)); copiedSig = true; setTimeout(() => copiedSig = false, 2000)"
                    >
                        <x-heroicon-o-clipboard class="h-4 w-4" />
                        <span x-show="!copiedSig">{{ __('Copy') }}</span>
                        <span x-show="copiedSig" x-cloak>{{ __('Copied') }}</span>
                    </button>
                    <button
                        type="button"
                        wire:click="rotateEdgeImageSigningSecret"
                        wire:loading.attr="disabled"
                        wire:target="rotateEdgeImageSigningSecret"
                        wire:confirm="{{ __('Rotate the signing secret? Any pre-signed image URLs you have already rendered will return 403 until re-signed.') }}"
                        class="inline-flex items-center gap-1 rounded-lg border border-rose-200 bg-white px-3 py-2 text-xs font-semibold text-rose-900 shadow-sm hover:bg-rose-50 dark:border-rose-900/40 dark:bg-zinc-900 dark:text-rose-300"
                    >
                        <x-heroicon-o-arrow-path class="h-3.5 w-3.5" />
                        {{ __('Rotate') }}
                    </button>
                </div>
            </div>
        @endif
    </section>
@endcan
