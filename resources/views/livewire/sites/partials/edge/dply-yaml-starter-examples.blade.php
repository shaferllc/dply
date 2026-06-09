@php
    $dplyRoutingYamlExample = <<<'YAML'
redirects:
  - from: /old-page
    to: /new-page
    status: 301
  - from: /blog/*
    to: /news/:splat
    status: 301

rewrites:
  - from: /api/*
    to: https://api.example.com/:splat

headers:
  - for: /assets/*
    values:
      Cache-Control: "public, max-age=31536000, immutable"
      X-Content-Type-Options: nosniff
YAML;

    $dplyFullYamlExample = <<<'YAML'
# dply.yaml at your repository root (or monorepo root set in Build settings)

build:
  command: npm ci && npm run build
  output: dist
  # root: apps/web   # optional monorepo subdirectory
  # node: "20"

redirects:
  - from: /old-page
    to: /new-page
    status: 301

rewrites:
  - from: /api/*
    to: https://api.example.com/:splat

headers:
  - for: /assets/*
    values:
      Cache-Control: "public, max-age=31536000, immutable"
YAML;
@endphp

<div class="mt-6 space-y-4">
    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Examples') }}</p>

    <div class="space-y-4">
        <div class="overflow-hidden rounded-xl border border-brand-ink/10 bg-brand-sand/15 dark:border-brand-mist/20 dark:bg-zinc-900/40" x-data="{ copied: false }">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-brand-ink/10 px-4 py-3">
                <div>
                    <p class="text-sm font-semibold text-brand-ink">{{ __('Routing rules only') }}</p>
                    <p class="mt-0.5 text-xs text-brand-moss">{{ __('Add redirects, rewrites, and header rules to an existing :file.', ['file' => 'dply.yaml']) }}</p>
                </div>
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/10 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40 dark:border-brand-mist/20 dark:bg-zinc-900"
                    @click="navigator.clipboard.writeText(@js(trim($dplyRoutingYamlExample))); copied = true; setTimeout(() => copied = false, 2000)"
                >
                    <x-heroicon-o-clipboard class="h-4 w-4" />
                    <span x-show="!copied">{{ __('Copy') }}</span>
                    <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                </button>
            </div>
            <pre class="overflow-x-auto px-4 py-4 font-mono text-[11px] leading-relaxed text-brand-ink">{{ trim($dplyRoutingYamlExample) }}</pre>
        </div>

        <div class="overflow-hidden rounded-xl border border-brand-ink/10 bg-brand-sand/15 dark:border-brand-mist/20 dark:bg-zinc-900/40" x-data="{ copied: false }">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-brand-ink/10 px-4 py-3">
                <div>
                    <p class="text-sm font-semibold text-brand-ink">{{ __('Starter dply.yaml') }}</p>
                    <p class="mt-0.5 text-xs text-brand-moss">{{ __('Build settings plus routing rules in one file. Commit at the repo root, then redeploy.') }}</p>
                </div>
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/10 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40 dark:border-brand-mist/20 dark:bg-zinc-900"
                    @click="navigator.clipboard.writeText(@js(trim($dplyFullYamlExample))); copied = true; setTimeout(() => copied = false, 2000)"
                >
                    <x-heroicon-o-clipboard class="h-4 w-4" />
                    <span x-show="!copied">{{ __('Copy') }}</span>
                    <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                </button>
            </div>
            <pre class="overflow-x-auto px-4 py-4 font-mono text-[11px] leading-relaxed text-brand-ink">{{ trim($dplyFullYamlExample) }}</pre>
        </div>
    </div>

    <p class="text-xs text-brand-moss">
        {{ __('Run `dply edge lint` locally before pushing. Dashboard build settings apply when :file omits a build block.', ['file' => 'dply.yaml']) }}
    </p>
</div>
