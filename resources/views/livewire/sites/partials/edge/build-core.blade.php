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


<section class="dply-card overflow-hidden">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
            <x-heroicon-o-folder class="h-5 w-5" aria-hidden="true" />
        </span>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Source') }}</p>
            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Repository & branch') }}</h3>
            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Source control settings from site creation. Changing these requires a new Edge site in v1.') }}</p>
        </div>
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
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-document-text class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Repo config') }}</p>
                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">
                    {{ __('Managed by :file', ['file' => $latestRepoConfig['source_path'] ?? 'dply.yaml']) }}
                </h3>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                    {{ __('Build settings from :file override the dashboard form below on each deploy. Redirects / rewrites / headers live on the :routing tab.', ['file' => $latestRepoConfig['source_path'] ?? 'dply.yaml', 'routing' => __('Routing')]) }}
                </p>
            </div>
            <span class="inline-flex shrink-0 items-center gap-1 rounded-full bg-brand-sand/60 px-2 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-wide text-brand-moss">
                {{ __('Repo config') }}
            </span>
        </div>
        <dl class="px-6 py-4 text-sm sm:px-8">
            <div>
                <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Build overrides') }}</dt>
                <dd class="mt-0.5 font-mono text-xs text-brand-ink">{{ empty($latestRepoConfig['build']) ? __('—') : count($latestRepoConfig['build']).' '.__('keys') }}</dd>
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

<section id="edge-build-configuration" class="scroll-mt-24 dply-card overflow-hidden">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
            <x-heroicon-o-wrench-screwdriver class="h-5 w-5" aria-hidden="true" />
        </span>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Build') }}</p>
            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Build configuration') }}</h3>
            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Command and output directory used on each deploy. Save here, then redeploy to apply.') }}</p>
        </div>
    </div>
    @can('update', $site)
        <form wire:submit.prevent="saveEdgeBuildSettings" class="space-y-5 px-6 py-5 sm:px-8">
            <label class="block">
                <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Build command') }}</span>
                <input
                    type="text"
                    wire:model="buildForm.edge_build_command"
                    autocomplete="off"
                    spellcheck="false"
                    class="mt-1.5 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900"
                />
                @error('buildForm.edge_build_command')
                    <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
                @enderror
            </label>
            <label class="block">
                <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Output directory') }}</span>
                <input
                    type="text"
                    wire:model="buildForm.edge_output_dir"
                    autocomplete="off"
                    spellcheck="false"
                    placeholder="dist"
                    class="mt-1.5 w-full max-w-xs rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900"
                />
                @error('buildForm.edge_output_dir')
                    <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
                @enderror
            </label>
            <label class="block">
                <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Repository root') }}</span>
                <input
                    type="text"
                    wire:model="buildForm.edge_repo_root"
                    autocomplete="off"
                    spellcheck="false"
                    placeholder="apps/web"
                    class="mt-1.5 w-full max-w-md rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900"
                />
                <p class="mt-1 text-xs text-brand-moss">{{ __('Optional monorepo subdirectory. Builds run from this folder; GitHub auto-deploy only triggers when changed files touch this path or dply.toml/yaml at the repo root.') }}</p>
                @error('buildForm.edge_repo_root')
                    <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
                @enderror
            </label>
            <div class="space-y-3">
                <label class="flex items-start gap-3 text-sm text-brand-ink">
                    <input type="checkbox" wire:model="buildForm.edge_spa_fallback" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage shadow-sm focus:ring-brand-sage/40" />
                    <span>
                        <span class="font-medium">{{ __('SPA fallback') }}</span>
                        <span class="mt-0.5 block text-xs text-brand-moss">{{ __('Unknown paths serve index.html after a 404.') }}</span>
                    </span>
                </label>
                <label class="flex items-start gap-3 text-sm text-brand-ink">
                    <input type="checkbox" wire:model="buildForm.edge_deploy_on_push" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage shadow-sm focus:ring-brand-sage/40" />
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

<section class="dply-card overflow-hidden">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
            <x-heroicon-o-archive-box class="h-5 w-5" aria-hidden="true" />
        </span>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Retention') }}</p>
            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Retention') }}</h3>
            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                {{ __('Older deployments beyond this count have their R2 artifacts deleted. Pruned deployments stay listed for audit but can\'t be rolled back without rebuilding from their commit.') }}
            </p>
        </div>
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
                        wire:model="buildForm.edge_releases_to_keep"
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
            <p class="text-sm text-brand-ink">{{ __('Releases to keep: :count', ['count' => $buildForm->edge_releases_to_keep]) }}</p>
        @endcan
    </div>
</section>
