@props([
    'compact' => false,
    'server' => null,
])

@php
    $serverName = $server?->name ?: __('this server');
@endphp

<div
    @class([
        'relative overflow-hidden',
        'rounded-2xl border border-brand-ink/10 bg-white shadow-sm ring-1 ring-brand-ink/[0.04]' => ! $compact,
        'rounded-xl' => $compact,
    ])
>
    <div @class([
        'relative overflow-hidden bg-[#0f1419]',
        'px-5 pb-6 pt-5 sm:px-6 sm:pb-7 sm:pt-6' => ! $compact,
        'px-4 pb-4 pt-3.5' => $compact,
    ])>
        <div class="pointer-events-none absolute -end-16 -top-20 h-56 w-56 rounded-full bg-sky-400/10 blur-3xl" aria-hidden="true"></div>
        <div class="pointer-events-none absolute -bottom-24 start-8 h-48 w-48 rounded-full bg-brand-sage/10 blur-3xl" aria-hidden="true"></div>

        <div class="relative flex items-start justify-between gap-3">
            <div class="flex items-center gap-2">
                <span class="inline-flex h-2.5 w-2.5 rounded-full bg-red-400/80" aria-hidden="true"></span>
                <span class="inline-flex h-2.5 w-2.5 rounded-full bg-amber-300/80" aria-hidden="true"></span>
                <span class="inline-flex h-2.5 w-2.5 rounded-full bg-emerald-400/80" aria-hidden="true"></span>
            </div>
            <span class="inline-flex items-center gap-1.5 rounded-full border border-white/10 bg-white/5 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-[0.16em] text-sky-200/90">
                <span class="relative flex h-1.5 w-1.5">
                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-sky-400/60 opacity-75"></span>
                    <span class="relative inline-flex h-1.5 w-1.5 rounded-full bg-sky-400"></span>
                </span>
                {{ __('Coming soon') }}
            </span>
        </div>

        <div class="relative mt-5 space-y-2 font-mono text-[11px] leading-relaxed sm:text-xs">
            <p class="text-slate-500">{{ __('# Remote file browser (read-only v1)') }}</p>
            <div class="mt-3 rounded-lg border border-white/8 bg-white/[0.03] px-3 py-2.5">
                <p class="text-sky-200/90">{{ __('path: /home/dply/site.com/current') }}</p>
                <p class="mt-1 text-slate-400">{{ __('running as: dply · filter: *.env') }}</p>
            </div>
            <div class="rounded-lg border border-white/8 bg-white/[0.03] px-3 py-2.5 text-slate-300">
                <p>drwxr-xr-x  site.com/</p>
                <p>-rw-r--r--  .env.example</p>
                <p>-rw-r--r--  storage/logs/laravel.log</p>
                <p class="mt-2 text-slate-500">{{ __('view · download (≤25 MB) · quick jumps to /etc/nginx') }}</p>
            </div>
        </div>

        @if ($server)
            <div class="relative mt-4 inline-flex items-center gap-2 rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-[11px] text-slate-300">
                <x-heroicon-o-folder class="h-3.5 w-3.5 shrink-0 text-sky-300/80" aria-hidden="true" />
                <span>{{ __('Files will browse :server over SSH without leaving the workspace.', ['server' => $serverName]) }}</span>
            </div>
        @endif
    </div>

    <div @class([
        'relative bg-gradient-to-b from-brand-cream to-white',
        'px-6 py-7 sm:px-8 sm:py-8' => ! $compact,
        'px-4 py-5' => $compact,
    ])>
        <div @class([
            'flex flex-col gap-6 sm:flex-row sm:items-start sm:justify-between' => ! $compact,
            'gap-4' => $compact,
        ])>
            <div @class(['max-w-md', 'text-center sm:text-left' => ! $compact])>
                <div @class([
                    'inline-flex items-center justify-center rounded-xl bg-sky-50 text-sky-800 ring-1 ring-sky-200/80',
                    'h-11 w-11' => ! $compact,
                    'h-9 w-9' => $compact,
                ])>
                    <x-heroicon-o-folder @class([
                        'shrink-0',
                        'h-6 w-6' => ! $compact,
                        'h-5 w-5' => $compact,
                    ]) aria-hidden="true" />
                </div>
                <h2 @class([
                    'font-semibold tracking-tight text-brand-ink',
                    'mt-4 text-xl sm:text-2xl' => ! $compact,
                    'mt-3 text-base' => $compact,
                ])>{{ __('Remote files') }}</h2>
                <p @class([
                    'leading-6 text-brand-moss',
                    'mt-2 text-sm sm:text-[15px]' => ! $compact,
                    'mt-1.5 text-xs' => $compact,
                ])>
                    {{ __('Browse the server filesystem over SSH — list directories, preview text and images inline, and download files within safe size caps. Owners and admins can optionally view as root with full audit logging.') }}
                </p>
            </div>
        </div>

        <ul @class([
            'grid gap-3',
            'mt-7 sm:grid-cols-2' => ! $compact,
            'mt-4' => $compact,
        ])>
            @foreach ([
                ['icon' => 'folder-open', 'title' => __('SSH directory browser'), 'body' => __('Navigate deploy-home paths, breadcrumbs, and quick jumps to common config dirs.')],
                ['icon' => 'document-magnifying-glass', 'title' => __('Inline previews'), 'body' => __('Open text files in a modal; binary files fall back to download-only.')],
                ['icon' => 'arrow-down-tray', 'title' => __('Capped downloads'), 'body' => __('Download files up to a configured limit — larger assets stay on Manage → Run.')],
                ['icon' => 'shield-check', 'title' => __('RBAC + audit'), 'body' => __('Deployers stay out; view-as-root toggles and sensitive opens hit the activity feed.')],
            ] as $feature)
                <li @class([
                    'flex gap-3 rounded-xl border border-brand-ink/8 bg-white/90 p-3.5 shadow-sm ring-1 ring-brand-ink/[0.03]',
                    'sm:p-4' => ! $compact,
                ])>
                    <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-sand/35 text-brand-forest ring-1 ring-brand-ink/8">
                        @switch($feature['icon'])
                            @case('folder-open')
                                <x-heroicon-o-folder-open class="h-4 w-4" aria-hidden="true" />
                                @break
                            @case('document-magnifying-glass')
                                <x-heroicon-o-document-magnifying-glass class="h-4 w-4" aria-hidden="true" />
                                @break
                            @case('arrow-down-tray')
                                <x-heroicon-o-arrow-down-tray class="h-4 w-4" aria-hidden="true" />
                                @break
                            @default
                                <x-heroicon-o-shield-check class="h-4 w-4" aria-hidden="true" />
                        @endswitch
                    </span>
                    <span class="min-w-0 text-left">
                        <span @class(['block font-semibold text-brand-ink', 'text-sm' => $compact])>{{ $feature['title'] }}</span>
                        <span @class(['mt-0.5 block leading-5 text-brand-moss', 'text-xs' => $compact, 'text-[13px]' => ! $compact])>{{ $feature['body'] }}</span>
                    </span>
                </li>
            @endforeach
        </ul>

        <div @class([
            'flex flex-col gap-3 border-t border-brand-ink/8 sm:flex-row sm:items-center sm:justify-between',
            'mt-7 pt-5' => ! $compact,
            'mt-5 pt-4' => $compact,
        ])>
            <p @class(['text-brand-moss', 'text-sm' => ! $compact, 'text-xs' => $compact])>
                {{ __('We will enable remote files for your org once read-only browsing and audit hooks are validated on production VMs.') }}
            </p>
            <span @class([
                'inline-flex items-center justify-center gap-1.5 rounded-full bg-brand-ink/[0.04] font-medium text-brand-mist',
                'px-3 py-1.5 text-xs' => ! $compact,
                'px-2.5 py-1 text-[10px]' => $compact,
            ])>
                <x-heroicon-o-clock class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                {{ __('In development') }}
            </span>
        </div>
    </div>
</div>
