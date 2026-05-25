@php
    use App\Models\EdgeDeployment;
    use App\Models\EdgeSiteAccessRule;

    $repoRootPath = trim((string) ($edge_repo_root ?? $site->edgeRepoRoot()));
    $previewMode = $edge_preview_protection_mode ?? EdgeSiteAccessRule::MODE_OFF;
    $previewLabel = match ($previewMode) {
        EdgeSiteAccessRule::MODE_PASSWORD => __('Password'),
        EdgeSiteAccessRule::MODE_DPLY_ACCOUNT => __('Dply account'),
        default => __('Off'),
    };
    $previewTone = $previewMode === EdgeSiteAccessRule::MODE_OFF
        ? 'text-brand-moss ring-brand-ink/10 bg-white/60'
        : 'text-brand-forest ring-brand-sage/40 bg-brand-sage/10 dark:text-brand-sage';

    $repoConfigPath = is_array($edgeBuildRepoConfig ?? null)
        ? (string) ($edgeBuildRepoConfig['source_path'] ?? 'dply.yaml')
        : null;
    $redirectCount = is_array($edgeBuildRepoConfig ?? null)
        ? count((array) ($edgeBuildRepoConfig['redirects'] ?? []))
        : 0;
    $rewriteCount = is_array($edgeBuildRepoConfig ?? null)
        ? count((array) ($edgeBuildRepoConfig['rewrites'] ?? []))
        : 0;
    $headerCount = is_array($edgeBuildRepoConfig ?? null)
        ? count((array) ($edgeBuildRepoConfig['headers'] ?? []))
        : 0;
    $routingCount = $redirectCount + $rewriteCount + $headerCount;

    $navItems = [
        ['id' => 'edge-build-repo-config', 'label' => __('Repo config'), 'show' => $repoConfigPath !== null],
        ['id' => 'edge-build-routing', 'label' => __('Routing rules'), 'show' => true],
        ['id' => 'edge-build-configuration', 'label' => __('Build settings'), 'show' => true],
        ['id' => 'edge-build-preview-protection', 'label' => __('Preview protection'), 'show' => ! ($edgeIsPreviewChild ?? false)],
    ];
@endphp

<section class="rounded-xl border border-brand-ink/10 bg-brand-cream/20 dark:bg-brand-ink/20">
    <div class="border-b border-brand-ink/10 px-5 py-3 sm:px-6">
        <p class="text-xs font-semibold uppercase tracking-wider text-brand-moss">{{ __('Build workspace') }}</p>
        <p class="mt-1 text-sm text-brand-ink">{{ __('Repo config, monorepo root, preview gates, and build command — jump to a section below.') }}</p>
    </div>

    <div class="flex flex-wrap gap-2 border-b border-brand-ink/10 px-5 py-3 sm:px-6">
        <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold ring-1 ring-inset {{ $repoConfigPath !== null ? 'text-brand-forest ring-brand-sage/40 bg-brand-sage/10 dark:text-brand-sage' : 'text-brand-moss ring-brand-ink/10 bg-white/60' }}">
            <x-heroicon-o-document-text class="h-3.5 w-3.5" aria-hidden="true" />
            {{ __('Repo config') }}:
            <span class="font-mono font-normal">{{ $repoConfigPath ?? __('Not on last deploy') }}</span>
        </span>
        <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold ring-1 ring-inset {{ $previewTone }}">
            <x-heroicon-o-lock-closed class="h-3.5 w-3.5" aria-hidden="true" />
            {{ __('Preview protection') }}: {{ $previewLabel }}
        </span>
        <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold ring-1 ring-inset {{ $repoRootPath !== '' ? 'text-brand-forest ring-brand-sage/40 bg-brand-sage/10 dark:text-brand-sage' : 'text-brand-moss ring-brand-ink/10 bg-white/60' }}">
            <x-heroicon-o-folder class="h-3.5 w-3.5" aria-hidden="true" />
            {{ __('Monorepo root') }}:
            <span class="font-mono font-normal">{{ $repoRootPath !== '' ? $repoRootPath : __('Repository root') }}</span>
        </span>
        @if ($routingCount > 0)
            <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold text-brand-moss ring-1 ring-inset ring-brand-ink/10 bg-white/60">
                {{ trans_choice(':count redirect|:count redirects', $redirectCount, ['count' => $redirectCount]) }}
                ·
                {{ trans_choice(':count rewrite|:count rewrites', $rewriteCount, ['count' => $rewriteCount]) }}
                ·
                {{ trans_choice(':count header rule|:count header rules', $headerCount, ['count' => $headerCount]) }}
            </span>
        @endif
    </div>

    <nav aria-label="{{ __('Build settings sections') }}" class="flex flex-wrap gap-2 px-5 py-3 sm:px-6">
        @foreach ($navItems as $item)
            @if ($item['show'])
                <a
                    href="#{{ $item['id'] }}"
                    class="inline-flex items-center rounded-lg border border-brand-ink/10 bg-white/70 px-3 py-1.5 text-xs font-semibold text-brand-ink transition hover:border-brand-sage/40 hover:bg-white dark:bg-brand-ink/30"
                >
                    {{ $item['label'] }}
                </a>
            @endif
        @endforeach
    </nav>
</section>
