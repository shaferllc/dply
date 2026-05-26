<div class="mx-auto max-w-6xl px-6 py-10">
    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Infrastructure'), 'href' => route('infrastructure.index'), 'icon' => 'rectangle-group'],
        ['label' => __('Cloud apps'), 'icon' => 'cloud'],
    ]" />

    <header class="mb-8 flex flex-wrap items-end justify-between gap-4">
        <div class="space-y-1.5">
            <div class="inline-flex items-center gap-2 rounded-full bg-brand-sand/40 px-3 py-1 text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-moss">
                <x-heroicon-o-cloud class="h-3 w-3" aria-hidden="true" />
                {{ __('Cloud') }}
            </div>
            <h1 class="text-3xl font-semibold tracking-tight text-brand-ink">{{ __('Apps') }}</h1>
            <p class="text-sm text-brand-moss">{{ __('Apps running on dply cloud across :org.', ['org' => $org->name]) }}</p>
        </div>
        <a href="{{ route('cloud.create') }}" wire:navigate class="inline-flex items-center gap-2 rounded-xl bg-brand-ink px-4 py-2.5 text-sm font-semibold text-brand-cream shadow-sm transition hover:bg-brand-ink/90">
            <x-heroicon-o-plus class="h-4 w-4" aria-hidden="true" />
            {{ __('Deploy an app') }}
        </a>
    </header>

    @if (! $hasAnyBackendCredential)
        <div class="dply-card mb-6 flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-start gap-3">
                <div class="mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-gold/20 text-brand-rust">
                    <x-heroicon-o-link class="h-4 w-4" aria-hidden="true" />
                </div>
                <div class="space-y-1">
                    <p class="text-sm font-semibold text-brand-ink">{{ __('Connect a cloud account to deploy') }}</p>
                    <p class="text-sm text-brand-moss">{{ __('dply needs a DigitalOcean or AWS account to run your apps on. Connect once and we handle the rest.') }}</p>
                </div>
            </div>
            <div class="flex shrink-0 items-center gap-2 text-xs">
                <a href="{{ route('credentials.index', ['provider' => 'digitalocean']) }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-3 py-2 font-semibold text-brand-cream hover:bg-brand-ink/90">
                    {{ __('Connect DigitalOcean') }}
                </a>
                <a href="{{ route('credentials.index', ['provider' => 'aws_app_runner']) }}" wire:navigate class="font-medium text-brand-moss hover:text-brand-ink">{{ __('Use AWS') }}</a>
            </div>
        </div>
    @endif

    <nav class="mb-5 flex flex-wrap items-center gap-1.5 text-xs">
        @php
            $tabs = [
                ['key' => 'all', 'label' => __('All'), 'count' => $totals['all']],
                ['key' => 'source', 'label' => __('Repository'), 'count' => $totals['source'] ?? 0],
                ['key' => 'image', 'label' => __('Image'), 'count' => $totals['image'] ?? 0],
                ['key' => 'previews', 'label' => __('Previews'), 'count' => $totals['previews'] ?? 0],
                ['key' => 'provisioning', 'label' => __('Provisioning'), 'count' => $totals['provisioning']],
                ['key' => 'failed', 'label' => __('Failed'), 'count' => $totals['failed']],
            ];
        @endphp
        @foreach ($tabs as $tab)
            <button
                type="button"
                wire:click="$set('filter', '{{ $tab['key'] }}')"
                @class([
                    'inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 font-medium transition',
                    'bg-brand-ink text-brand-cream' => $filter === $tab['key'],
                    'bg-brand-cream text-brand-moss ring-1 ring-brand-ink/10 hover:bg-brand-sand/40 hover:text-brand-ink' => $filter !== $tab['key'],
                ])
            >
                {{ $tab['label'] }}
                <span @class([
                    'rounded-full px-1.5 font-mono text-[10px] leading-4',
                    'bg-brand-cream/15 text-brand-cream' => $filter === $tab['key'],
                    'bg-brand-ink/5 text-brand-mist' => $filter !== $tab['key'],
                ])>{{ $tab['count'] }}</span>
            </button>
        @endforeach
    </nav>

    @if ($sites->isEmpty() && $filter === 'all')
        {{-- Onboarding: the welcoming version of "no apps yet" for first-time users. --}}
        <section class="space-y-6">
            <div class="dply-card relative overflow-hidden px-6 py-10 sm:px-10 sm:py-12">
                <div class="pointer-events-none absolute -right-16 -top-16 h-48 w-48 rounded-full bg-brand-sage/15 blur-3xl"></div>
                <div class="pointer-events-none absolute -bottom-20 -left-10 h-56 w-56 rounded-full bg-brand-gold/10 blur-3xl"></div>
                <div class="relative max-w-xl space-y-3">
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-sand/40 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-moss">
                        <x-heroicon-o-sparkles class="h-3 w-3" aria-hidden="true" />
                        {{ __('Get started') }}
                    </span>
                    <h2 class="text-2xl font-semibold tracking-tight text-brand-ink sm:text-3xl">{{ __('Ship your first app in minutes') }}</h2>
                    <p class="text-sm leading-relaxed text-brand-moss">{{ __('Point dply at a repository or pre-built image and we handle the rest — global HTTPS, auto-scaling, branch previews, and zero-config TLS. No infrastructure to wire up.') }}</p>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <a href="{{ route('cloud.create', ['mode' => 'source']) }}" wire:navigate class="group dply-card flex h-full flex-col gap-4 p-6 transition hover:border-brand-ink/20 hover:shadow-md">
                    <div class="flex items-center justify-between">
                        <div class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest">
                            <x-heroicon-o-code-bracket class="h-5 w-5" aria-hidden="true" />
                        </div>
                        <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-700">{{ __('Recommended') }}</span>
                    </div>
                    <div class="space-y-1.5">
                        <h3 class="text-base font-semibold text-brand-ink">{{ __('Deploy from a repository') }}</h3>
                        <p class="text-sm leading-relaxed text-brand-moss">{{ __('Pick a GitHub repo and we\'ll build, deploy, and re-deploy on every push. Branch previews come free.') }}</p>
                    </div>
                    <div class="mt-auto inline-flex items-center gap-1.5 text-sm font-semibold text-brand-ink group-hover:gap-2 transition-all">
                        {{ __('Connect a repo') }}
                        <x-heroicon-o-arrow-right class="h-4 w-4" aria-hidden="true" />
                    </div>
                </a>

                <a href="{{ route('cloud.create', ['mode' => 'image']) }}" wire:navigate class="group dply-card flex h-full flex-col gap-4 p-6 transition hover:border-brand-ink/20 hover:shadow-md">
                    <div class="flex items-center justify-between">
                        <div class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-brand-sand/40 text-brand-moss">
                            <x-heroicon-o-cube class="h-5 w-5" aria-hidden="true" />
                        </div>
                    </div>
                    <div class="space-y-1.5">
                        <h3 class="text-base font-semibold text-brand-ink">{{ __('Deploy a pre-built image') }}</h3>
                        <p class="text-sm leading-relaxed text-brand-moss">{{ __('Already pushing to a registry? Drop in a Docker image tag and dply launches it. Great for CI-built artifacts.') }}</p>
                    </div>
                    <div class="mt-auto inline-flex items-center gap-1.5 text-sm font-semibold text-brand-ink group-hover:gap-2 transition-all">
                        {{ __('Deploy an image') }}
                        <x-heroicon-o-arrow-right class="h-4 w-4" aria-hidden="true" />
                    </div>
                </a>
            </div>

            <div class="dply-card p-6 sm:p-7">
                <div class="mb-5 flex items-center gap-2">
                    <span class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('What you get') }}</span>
                    <span class="h-px flex-1 bg-brand-ink/10"></span>
                </div>
                <div class="grid gap-x-6 gap-y-5 sm:grid-cols-2 lg:grid-cols-3">
                    @php
                        $features = [
                            ['icon' => 'lock-closed', 'title' => __('Global HTTPS'), 'body' => __('Automatic TLS on every app and preview hostname — no cert wrangling.')],
                            ['icon' => 'arrows-up-down', 'title' => __('Auto-scaling'), 'body' => __('Set min/max instances and a CPU target; dply scales the fleet as traffic moves.')],
                            ['icon' => 'document-duplicate', 'title' => __('Branch previews'), 'body' => __('Every PR gets its own ephemeral deploy with a unique URL. Free to spin up.')],
                            ['icon' => 'circle-stack', 'title' => __('Managed databases'), 'body' => __('Attach Postgres, MySQL, or Redis at deploy time — credentials are wired in automatically.')],
                            ['icon' => 'cpu-chip', 'title' => __('Workers & schedulers'), 'body' => __('Long-running queue workers and a Laravel scheduler alongside the same source.')],
                            ['icon' => 'wrench-screwdriver', 'title' => __('Dockerfile or buildpacks'), 'body' => __('Drop in a Dockerfile or let dply auto-detect the runtime and build it for you.')],
                        ];
                    @endphp
                    @foreach ($features as $feat)
                        <div class="flex items-start gap-3">
                            <div class="mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-cream/70 text-brand-moss ring-1 ring-brand-ink/5">
                                @switch($feat['icon'])
                                    @case('lock-closed') <x-heroicon-o-lock-closed class="h-4 w-4" aria-hidden="true" /> @break
                                    @case('arrows-up-down') <x-heroicon-o-arrows-up-down class="h-4 w-4" aria-hidden="true" /> @break
                                    @case('document-duplicate') <x-heroicon-o-document-duplicate class="h-4 w-4" aria-hidden="true" /> @break
                                    @case('circle-stack') <x-heroicon-o-circle-stack class="h-4 w-4" aria-hidden="true" /> @break
                                    @case('cpu-chip') <x-heroicon-o-cpu-chip class="h-4 w-4" aria-hidden="true" /> @break
                                    @case('wrench-screwdriver') <x-heroicon-o-wrench-screwdriver class="h-4 w-4" aria-hidden="true" /> @break
                                @endswitch
                            </div>
                            <div class="space-y-0.5">
                                <p class="text-sm font-semibold text-brand-ink">{{ $feat['title'] }}</p>
                                <p class="text-xs leading-relaxed text-brand-moss">{{ $feat['body'] }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    @elseif ($sites->isEmpty())
        {{-- Filtered list is empty: keep the lightweight inline state. --}}
        <div class="dply-card flex flex-col items-center justify-center gap-2 px-6 py-12 text-center">
            <div class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-brand-sand/40 text-brand-moss">
                <x-heroicon-o-funnel class="h-5 w-5" aria-hidden="true" />
            </div>
            <p class="text-sm font-semibold text-brand-ink">{{ __('No apps match this filter') }}</p>
            <button type="button" wire:click="$set('filter', 'all')" class="text-xs font-medium text-brand-sage hover:text-brand-ink underline underline-offset-2">{{ __('Clear filter') }}</button>
        </div>
    @else
        <ul role="list" class="space-y-2">
            @foreach ($sites as $site)
                @php
                    $statusMeta = match ($site->status) {
                        \App\Models\Site::STATUS_CONTAINER_ACTIVE => ['label' => __('Live'), 'dot' => 'bg-emerald-500', 'pulse' => true, 'text' => 'text-emerald-700'],
                        \App\Models\Site::STATUS_CONTAINER_PROVISIONING => ['label' => __('Provisioning'), 'dot' => 'bg-sky-500', 'pulse' => true, 'text' => 'text-sky-700'],
                        \App\Models\Site::STATUS_CONTAINER_FAILED => ['label' => __('Failed'), 'dot' => 'bg-rose-500', 'pulse' => false, 'text' => 'text-rose-700'],
                        default => ['label' => str_replace('_', ' ', (string) $site->status), 'dot' => 'bg-brand-mist', 'pulse' => false, 'text' => 'text-brand-moss'],
                    };
                    $liveUrl = $site->containerLiveUrl();
                    $displayUrl = $liveUrl ? preg_replace('#^https?://#', '', (string) $liveUrl) : null;
                    $rowSource = is_array($site->meta['container']['source'] ?? null) ? $site->meta['container']['source'] : null;
                    $rowPreviewBranch = $site->meta['container']['preview_branch'] ?? null;
                    $rowPreviewPr = $site->meta['container']['preview_pr_number'] ?? null;
                    $sourceLabel = $rowSource
                        ? ($rowSource['repo'] ?? '?').'@'.($rowSource['branch'] ?? 'main')
                        : ($site->container_image ?: null);
                @endphp
                <li wire:key="cloud-app-{{ $site->id }}">
                    <a
                        href="{{ route('sites.show', ['server' => $site->server, 'site' => $site]) }}"
                        wire:navigate
                        class="group dply-card flex flex-col gap-4 px-5 py-4 transition hover:border-brand-ink/20 hover:shadow-md sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div class="min-w-0 flex-1 space-y-1.5">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="truncate text-base font-semibold text-brand-ink">{{ $site->name }}</span>
                                @if ($rowPreviewBranch)
                                    <span class="inline-flex items-center gap-1 rounded-full bg-indigo-50 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-[0.14em] text-indigo-700 ring-1 ring-indigo-100">
                                        @if ($rowPreviewPr)
                                            PR #{{ $rowPreviewPr }}
                                        @else
                                            {{ __('Preview') }}
                                        @endif
                                    </span>
                                @endif
                            </div>
                            @if ($displayUrl)
                                <div class="flex items-center gap-1.5 text-sm text-brand-sage">
                                    <x-heroicon-o-arrow-top-right-on-square class="h-3.5 w-3.5 shrink-0 opacity-80" aria-hidden="true" />
                                    <span class="truncate font-mono text-xs">{{ $displayUrl }}</span>
                                </div>
                            @else
                                <div class="text-xs text-brand-mist">{{ __('Live URL pending') }}</div>
                            @endif
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-brand-mist">
                                @if ($site->container_region)
                                    <span class="inline-flex items-center gap-1">
                                        <x-heroicon-o-globe-alt class="h-3 w-3" aria-hidden="true" />
                                        <span class="font-mono">{{ $site->container_region }}</span>
                                    </span>
                                @endif
                                @if ($sourceLabel)
                                    <span class="inline-flex items-center gap-1">
                                        @if ($rowSource)
                                            <x-heroicon-o-code-bracket class="h-3 w-3" aria-hidden="true" />
                                        @else
                                            <x-heroicon-o-cube class="h-3 w-3" aria-hidden="true" />
                                        @endif
                                        <span class="truncate font-mono">{{ $sourceLabel }}</span>
                                    </span>
                                @endif
                            </div>
                        </div>
                        <div class="flex shrink-0 items-center gap-4">
                            <div class="inline-flex items-center gap-2">
                                <span class="relative inline-flex h-2 w-2">
                                    @if ($statusMeta['pulse'])
                                        <span class="absolute inline-flex h-full w-full animate-ping rounded-full {{ $statusMeta['dot'] }} opacity-60"></span>
                                    @endif
                                    <span class="relative inline-flex h-2 w-2 rounded-full {{ $statusMeta['dot'] }}"></span>
                                </span>
                                <span class="text-xs font-semibold {{ $statusMeta['text'] }}">{{ $statusMeta['label'] }}</span>
                            </div>
                            <x-heroicon-o-chevron-right class="h-4 w-4 shrink-0 text-brand-mist transition group-hover:translate-x-0.5 group-hover:text-brand-moss" aria-hidden="true" />
                        </div>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</div>
