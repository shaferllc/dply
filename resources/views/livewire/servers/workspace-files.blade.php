@php
    $crumbs = [];
    $accum = '';
    foreach (explode('/', trim($path, '/')) as $segment) {
        if ($segment === '') {
            continue;
        }
        $accum .= '/'.$segment;
        $crumbs[] = ['name' => $segment, 'path' => $accum];
    }

    $filesDescription = __('Read-only filesystem browser over SSH. View text or image previews and download files (up to :mb MB).', [
        'mb' => (int) ($downloadMaxBytes / 1024 / 1024),
    ]);
@endphp

<x-server-workspace-layout
    :server="$server"
    active="files"
    :title="__('Files')"
    :description="$filesDescription"
    hide-hero
>
    <div
        wire:loading.flex
        wire:target="openFile, openEntry, jumpTo, goUp, toggleViewAsRoot"
        class="fixed inset-0 z-[60] hidden items-center justify-center bg-brand-ink/40 backdrop-blur-sm"
    >
        <div class="flex items-center gap-3 rounded-2xl bg-white px-5 py-4 shadow-xl ring-1 ring-brand-ink/10">
            <x-spinner variant="forest" />
            <span class="text-sm font-medium text-brand-ink">{{ __('Loading…') }}</span>
        </div>
    </div>

    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <section class="dply-card min-w-0 overflow-hidden p-0">
        <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-5 sm:px-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                        <x-heroicon-o-folder class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold tracking-tight text-brand-ink">{{ __('Files') }}</h2>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                            {{ $filesDescription }}
                        </p>
                    </div>
                </div>
            </div>
        </div>

        @if (! $opsReady)
            <div class="border-b border-brand-ink/10 px-5 py-5 sm:px-6">
                @include('livewire.servers.partials.workspace-ops-not-ready', ['server' => $server])
            </div>
        @else
            <div class="space-y-2.5 border-b border-brand-ink/10 px-4 py-3 sm:px-5">
                {{-- Path row: breadcrumbs + up + identity / root toggle --}}
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <div class="flex min-w-0 flex-1 flex-wrap items-center gap-1">
                        <button
                            type="button"
                            wire:click="goUp"
                            title="{{ __('Parent directory') }}"
                            aria-label="{{ __('Parent directory') }}"
                            class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-brand-ink/10 bg-white text-brand-ink shadow-sm hover:bg-brand-sand/40"
                        >
                            <x-heroicon-o-arrow-uturn-left class="h-4 w-4" aria-hidden="true" />
                        </button>

                        <nav class="flex min-w-0 flex-1 flex-wrap items-center gap-0.5 rounded-lg border border-brand-ink/10 bg-white px-1.5 py-1 shadow-sm" aria-label="{{ __('Current path') }}">
                            <button
                                type="button"
                                wire:click="jumpTo('/')"
                                class="inline-flex h-7 items-center rounded-md px-2 font-mono text-xs text-brand-moss hover:bg-brand-sand/50 hover:text-brand-ink"
                            >/</button>
                            @foreach ($crumbs as $i => $crumb)
                                <span class="select-none text-brand-mist" aria-hidden="true">/</span>
                                @if ($i === count($crumbs) - 1)
                                    <span class="inline-flex h-7 max-w-[12rem] items-center truncate rounded-md bg-brand-ink/8 px-2 font-mono text-xs font-semibold text-brand-ink" aria-current="page">{{ $crumb['name'] }}</span>
                                @else
                                    <button
                                        type="button"
                                        wire:click="jumpTo('{{ $crumb['path'] }}')"
                                        class="inline-flex h-7 max-w-[10rem] items-center truncate rounded-md px-2 font-mono text-xs text-brand-moss hover:bg-brand-sand/50 hover:text-brand-ink"
                                    >{{ $crumb['name'] }}</button>
                                @endif
                            @endforeach
                        </nav>
                    </div>

                    <div class="flex shrink-0 flex-wrap items-center gap-2">
                        <span @class([
                            'inline-flex h-9 items-center gap-1.5 rounded-lg border px-2.5 font-mono text-xs font-semibold shadow-sm',
                            'border-red-200 bg-red-50 text-red-700' => $viewAsRoot,
                            'border-brand-ink/10 bg-white text-brand-ink' => ! $viewAsRoot,
                        ])>
                            <x-heroicon-o-user class="h-3.5 w-3.5 shrink-0 opacity-70" aria-hidden="true" />
                            <span class="sr-only">{{ __('Running as') }}</span>
                            {{ $effectiveLoginUser }}
                        </span>

                        @if ($canViewAsRoot)
                            <button
                                type="button"
                                wire:click="toggleViewAsRoot"
                                @class([
                                    'inline-flex h-9 items-center rounded-lg border px-3 text-xs font-semibold shadow-sm transition-colors',
                                    'border-red-300 bg-red-50 text-red-700 hover:bg-red-100' => $viewAsRoot,
                                    'border-brand-ink/10 bg-white text-brand-ink hover:bg-brand-sand/40' => ! $viewAsRoot,
                                ])
                            >
                                {{ $viewAsRoot ? __('Drop root') : __('View as root') }}
                            </button>
                        @endif
                    </div>
                </div>

                {{-- Jumps + filter on one compact row --}}
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                    @if (count($quickJumps) > 0)
                        <div class="flex min-w-0 flex-1 flex-wrap items-center gap-1.5">
                            <span class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Jump') }}</span>
                            @foreach ($quickJumps as $qj)
                                <button
                                    type="button"
                                    wire:click="jumpTo('{{ $qj }}')"
                                    @class([
                                        'inline-flex h-7 items-center rounded-md border px-2 font-mono text-[11px] transition-colors',
                                        'border-brand-ink/15 bg-brand-ink/8 font-semibold text-brand-ink' => $path === $qj,
                                        'border-brand-ink/10 bg-brand-sand/30 text-brand-ink hover:bg-brand-sand/60' => $path !== $qj,
                                    ])
                                >{{ $qj }}</button>
                            @endforeach
                        </div>
                    @endif

                    <label class="relative block w-full sm:w-56 sm:shrink-0">
                        <span class="sr-only">{{ __('Filter (glob)') }}</span>
                        <x-heroicon-o-magnifying-glass
                            class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-brand-mist"
                            aria-hidden="true"
                        />
                        <input
                            type="text"
                            wire:model.live.debounce.300ms="filter"
                            placeholder="{{ __('Filter… *.conf') }}"
                            class="h-9 w-full rounded-lg border border-brand-ink/10 bg-white py-1.5 pe-3 ps-9 font-mono text-xs leading-none text-brand-ink shadow-sm placeholder:text-brand-mist focus:border-brand-forest focus:outline-none focus:ring-1 focus:ring-brand-forest"
                        >
                    </label>
                </div>

                @if ($viewAsRoot)
                    <p class="text-[11px] leading-snug text-red-700">
                        {{ __('Browsing as root. Every toggle is recorded in the activity feed.') }}
                    </p>
                @endif
            </div>

            @if ($listing)
                @if ($listing->truncated)
                    <div class="border-b border-amber-200/80 bg-amber-50/70 px-5 py-3 text-sm text-amber-900 sm:px-6">
                        {{ __('Showing :shown of :total entries. Use the filter above or open Manage → Run for a full listing.', ['shown' => count($listing->entries), 'total' => $listing->totalCount]) }}
                    </div>
                @endif

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-brand-ink/10 text-left text-xs">
                        <thead class="bg-brand-sand/30 text-brand-moss">
                            <tr>
                                <th class="px-4 py-2 font-medium">{{ __('Name') }}</th>
                                <th class="px-4 py-2 font-medium">{{ __('Size') }}</th>
                                <th class="px-4 py-2 font-medium">{{ __('Modified') }}</th>
                                <th class="px-4 py-2 font-medium">{{ __('Mode') }}</th>
                                <th class="px-4 py-2 font-medium">{{ __('Owner') }}</th>
                                <th class="px-4 py-2 text-right font-medium">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-ink/10 text-brand-ink">
                            @forelse ($listing->entries as $entry)
                                @php
                                    try {
                                        $entryDownloadPath = \App\Support\Servers\FileBrowserPathPolicy::join($path, $entry->name);
                                        $entryDownloadUrl = route('servers.files.download', array_filter([
                                            'server' => $server,
                                            'path' => $entryDownloadPath,
                                            'root' => ($viewAsRoot && $canViewAsRoot) ? '1' : null,
                                        ]));
                                    } catch (\InvalidArgumentException) {
                                        $entryDownloadUrl = null;
                                    }
                                @endphp
                                <tr class="hover:bg-brand-sand/20">
                                    <td class="whitespace-nowrap px-4 py-2 font-mono">
                                        {{-- Links before dirs: directory symlinks report isDir()=true but still need → / Follow. --}}
                                        @if ($entry->isLink())
                                            @if ($entry->linkTargetIsDir)
                                                <button type="button" wire:click="openEntry('{{ addslashes($entry->name) }}', '{{ addslashes((string) $entry->linkTarget) }}')" class="inline-flex items-center gap-2 text-brand-forest hover:underline">
                                                    <x-heroicon-o-link class="h-4 w-4 shrink-0 text-brand-sage" />
                                                    <span>{{ $entry->name }}/</span>
                                                </button>
                                            @else
                                                @if ($entryDownloadUrl)
                                                    <a href="{{ $entryDownloadUrl }}" class="inline-flex items-center gap-2 text-brand-forest hover:underline">
                                                        <x-heroicon-o-link class="h-4 w-4 shrink-0 text-brand-sage" />
                                                        <span>{{ $entry->name }}</span>
                                                    </a>
                                                @else
                                                    <span class="inline-flex items-center gap-2 text-brand-forest">
                                                        <x-heroicon-o-link class="h-4 w-4 shrink-0 text-brand-sage" />
                                                        <span>{{ $entry->name }}</span>
                                                    </span>
                                                @endif
                                            @endif
                                            <span class="ml-1 text-brand-moss">→ {{ $entry->linkTarget }}</span>
                                        @elseif ($entry->isDir())
                                            <button type="button" wire:click="openEntry('{{ addslashes($entry->name) }}')" class="inline-flex items-center gap-2 text-brand-forest hover:underline">
                                                <x-heroicon-o-folder class="h-4 w-4 shrink-0 text-brand-sage" />
                                                <span>{{ $entry->name }}/</span>
                                            </button>
                                        @else
                                            <button type="button" wire:click="openFile('{{ addslashes($entry->name) }}')" class="inline-flex items-center gap-2 text-brand-ink hover:underline">
                                                <x-heroicon-o-document class="h-4 w-4 shrink-0 text-brand-mist" />
                                                <span>{{ $entry->name }}</span>
                                            </button>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-2 text-brand-moss">{{ $entry->isFile() ? number_format($entry->size) : '—' }}</td>
                                    <td class="whitespace-nowrap px-4 py-2 text-brand-moss" title="{{ \Carbon\Carbon::createFromTimestamp($entry->mtime)->format('Y-m-d H:i:s') }}">{{ \Carbon\Carbon::createFromTimestamp($entry->mtime)->diffForHumans() }}</td>
                                    <td class="whitespace-nowrap px-4 py-2 font-mono text-brand-moss">{{ $entry->mode }}</td>
                                    <td class="whitespace-nowrap px-4 py-2 text-brand-moss">{{ $entry->owner }}:{{ $entry->group }}</td>
                                    <td class="whitespace-nowrap px-4 py-2 text-right">
                                        @if ($entry->isLink() && $entry->linkTargetIsDir)
                                            <button type="button" wire:click="openEntry('{{ addslashes($entry->name) }}', '{{ addslashes((string) $entry->linkTarget) }}')" class="font-semibold text-brand-forest hover:underline">{{ __('Follow') }}</button>
                                        @elseif ($entry->isFile() || ($entry->isLink() && ! $entry->linkTargetIsDir))
                                            <div class="inline-flex items-center gap-2">
                                                <button type="button" wire:click="openFile('{{ addslashes($entry->name) }}')" class="font-semibold text-brand-ink hover:underline">{{ __('View') }}</button>
                                                @if ($entryDownloadUrl)
                                                    <a
                                                        href="{{ $entryDownloadUrl }}"
                                                        class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-forest shadow-sm transition-colors hover:bg-brand-sand/40"
                                                    >
                                                        <x-heroicon-o-arrow-down-tray class="h-4 w-4" />
                                                        {{ __('Download') }}
                                                    </a>
                                                @endif
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-6 py-10 text-center text-brand-moss">{{ __('Empty directory or no matches.') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endif
        @endif
    </section>

    @if ($showFileModal)
        @php
            $viewingIsImage = $viewingMime && str_starts_with($viewingMime, 'image/');
            $viewingImagePreviewable = $viewingIsImage && $viewingSize !== null && $viewingSize <= $downloadMaxBytes;
            $viewingImageUrl = null;
            if ($viewingImagePreviewable && $viewingPath !== null) {
                try {
                    $viewingImageUrl = route('servers.files.download', array_filter([
                        'server' => $server,
                        'path' => $viewingPath,
                        'root' => ($viewAsRoot && $canViewAsRoot) ? '1' : null,
                    ]));
                } catch (\InvalidArgumentException) {
                    $viewingImageUrl = null;
                }
            }
        @endphp
        <x-modal name="file-view" :show="true" max-width="4xl">
            <div class="space-y-4 p-6">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-xs uppercase tracking-wide text-brand-moss">{{ __('File') }}</p>
                        <p class="break-all font-mono text-sm font-semibold">{{ $viewingPath }}</p>
                        @if ($viewingMime)
                            <p class="mt-1 text-xs text-brand-moss">{{ $viewingMime }} · {{ number_format((int) $viewingSize) }} bytes</p>
                        @endif
                    </div>
                    <button type="button" wire:click="closeFileModal" class="text-sm text-brand-moss hover:underline">{{ __('Close') }}</button>
                </div>

                @if ($viewingError)
                    <div class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{{ $viewingError }}</div>
                @elseif ($viewingImageUrl)
                    <div class="flex max-h-[60vh] items-center justify-center overflow-auto rounded-md border border-brand-ink/10 bg-brand-ink/5 p-3">
                        <img src="{{ $viewingImageUrl }}" alt="{{ basename((string) $viewingPath) }}" class="max-h-[56vh] max-w-full object-contain" />
                    </div>
                @elseif ($viewingTruncated)
                    <div class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                        {{ __('File is larger than the inline cap (:cap MB). Use Download.', ['cap' => (int) ($editMaxBytes / 1024 / 1024)]) }}
                    </div>
                @elseif ($viewingIsBinary)
                    <div class="rounded-md border border-brand-ink/10 bg-brand-sand/30 px-3 py-2 text-sm text-brand-moss">
                        {{ __('Binary file — preview unavailable. Use Download.') }}
                    </div>
                @else
                    <pre class="max-h-[60vh] overflow-auto rounded-md border border-brand-ink/10 bg-brand-ink/5 p-3 text-xs leading-relaxed text-brand-ink"><code>{{ $viewingContent }}</code></pre>
                @endif
            </div>
        </x-modal>
    @endif
</x-server-workspace-layout>
