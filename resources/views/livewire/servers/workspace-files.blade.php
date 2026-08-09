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
    {{-- No full-screen blur-and-lock while a directory loads: every control that
         round-trips now swaps its own icon for a spinner in place, so the
         feedback sits where you clicked instead of over the whole page. --}}
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    @php
        // Shown / total when the listing was capped, so the count never
        // under-reports what's actually in the directory.
        $filesCount = $listing
            ? ($listing->truncated
                ? count($listing->entries).' / '.number_format($listing->totalCount)
                : number_format(count($listing->entries)))
            : null;
    @endphp

    <section class="dply-card min-w-0 overflow-hidden p-0">
        {{-- Same chrome as Settings: a dense panel head owns the identity — icon,
             title, entry count, standing description — and the row below is only
             controls. The title used to ride the toolbar as an inline cluster
             with the description hidden in its tooltip. That kept the card to a
             single line, but it made Files the one workspace whose heading didn't
             match its siblings and buried the description. Costs one row. --}}
        <x-workspace-panel-head
            dense
            icon="heroicon-o-folder"
            :title="__('Files')"
            :note="$filesDescription"
            :count="$filesCount"
            class="border-b border-brand-ink/10"
        />

        <div class="border-b border-brand-ink/10 px-4 py-1.5 sm:px-5">
            <div class="flex flex-wrap items-center gap-1.5">
                @if ($opsReady)
                    <button
                        type="button"
                        wire:click="goUp"
                        title="{{ __('Parent directory') }}"
                        aria-label="{{ __('Parent directory') }}"
                        class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg border border-brand-ink/10 bg-white text-brand-ink shadow-sm hover:bg-brand-sand/40"
                    >
                        <x-heroicon-o-arrow-uturn-left class="h-3.5 w-3.5" aria-hidden="true" wire:loading.remove wire:target="goUp" />
                        <x-spinner size="sm" wire:loading wire:target="goUp" />
                    </button>

                    <nav class="flex h-7 min-w-[10rem] flex-1 items-center gap-0.5 overflow-x-auto rounded-lg border border-brand-ink/10 bg-white px-1.5 shadow-sm [scrollbar-width:none] [&::-webkit-scrollbar]:hidden" aria-label="{{ __('Current path') }}">
                        {{-- Each crumb / jump target spins on its own wire:target so the
                             feedback lands on the segment you actually clicked. --}}
                        <button
                            type="button"
                            wire:click="jumpTo('/')"
                            class="inline-flex h-5 shrink-0 items-center gap-1 rounded px-1.5 font-mono text-xs text-brand-moss hover:bg-brand-sand/50 hover:text-brand-ink"
                        >
                            <x-spinner size="sm" class="h-3 w-3" wire:loading wire:target="jumpTo('/')" />
                            /
                        </button>
                        @foreach ($crumbs as $i => $crumb)
                            <span class="shrink-0 select-none text-brand-mist" aria-hidden="true">/</span>
                            @if ($i === count($crumbs) - 1)
                                <span class="inline-flex h-5 max-w-[14rem] shrink-0 items-center truncate rounded bg-brand-ink/8 px-1.5 font-mono text-xs font-semibold text-brand-ink" aria-current="page">{{ $crumb['name'] }}</span>
                            @else
                                <button
                                    type="button"
                                    wire:click="jumpTo('{{ $crumb['path'] }}')"
                                    class="inline-flex h-5 max-w-[10rem] shrink-0 items-center gap-1 truncate rounded px-1.5 font-mono text-xs text-brand-moss hover:bg-brand-sand/50 hover:text-brand-ink"
                                >
                                    <x-spinner size="sm" class="h-3 w-3" wire:loading wire:target="jumpTo('{{ $crumb['path'] }}')" />
                                    {{ $crumb['name'] }}
                                </button>
                            @endif
                        @endforeach
                    </nav>

                    @if (count($quickJumps) > 0)
                        {{-- No "JUMP" label — these read as paths, and they sit in
                             their own hairline group next to the path bar. --}}
                        <div class="flex shrink-0 items-center gap-1 rounded-lg border border-brand-ink/10 bg-white p-0.5 shadow-sm">
                            @foreach ($quickJumps as $qj)
                                <button
                                    type="button"
                                    wire:click="jumpTo('{{ $qj }}')"
                                    title="{{ __('Jump to :path', ['path' => $qj]) }}"
                                    @class([
                                        'inline-flex h-6 items-center gap-1 rounded-md px-2 font-mono text-xs transition-colors',
                                        'bg-brand-ink/8 font-semibold text-brand-ink' => $path === $qj,
                                        'text-brand-moss hover:bg-brand-sand/50 hover:text-brand-ink' => $path !== $qj,
                                    ])
                                >
                                    <x-spinner size="sm" class="h-3 w-3" wire:loading wire:target="jumpTo('{{ $qj }}')" />
                                    {{ $qj }}
                                </button>
                            @endforeach
                        </div>
                    @endif

                    <label class="relative block w-full shrink-0 sm:w-48">
                        <span class="sr-only">{{ __('Filter (glob)') }}</span>
                        <span class="pointer-events-none absolute left-2.5 top-1/2 inline-flex h-3.5 w-3.5 -translate-y-1/2 items-center justify-center">
                            <x-heroicon-o-magnifying-glass
                                class="h-3.5 w-3.5 text-brand-mist"
                                aria-hidden="true"
                                wire:loading.remove
                                wire:target="filter"
                            />
                            <x-spinner size="sm" wire:loading wire:target="filter" />
                        </span>
                        <input
                            type="text"
                            wire:model.live.debounce.300ms="filter"
                            placeholder="{{ __('Filter… *.conf') }}"
                            class="h-7 w-full rounded-lg border border-brand-ink/10 bg-white py-1 pe-2.5 ps-8 font-mono text-xs leading-none text-brand-ink shadow-sm placeholder:text-brand-mist focus:border-brand-forest focus:outline-none focus:ring-1 focus:ring-brand-forest"
                        >
                    </label>

                    <span @class([
                        'inline-flex h-7 shrink-0 items-center gap-1.5 rounded-lg border px-2 font-mono text-xs font-semibold shadow-sm',
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
                                'inline-flex h-7 shrink-0 items-center gap-1.5 rounded-lg border px-2.5 text-xs font-semibold shadow-sm transition-colors',
                                'border-red-300 bg-red-50 text-red-700 hover:bg-red-100' => $viewAsRoot,
                                'border-brand-ink/10 bg-white text-brand-ink hover:bg-brand-sand/40' => ! $viewAsRoot,
                            ])
                        >
                            <x-spinner size="sm" class="h-3 w-3" wire:loading wire:target="toggleViewAsRoot" />
                            {{ $viewAsRoot ? __('Drop root') : __('View as root') }}
                        </button>
                    @endif
                @endif
            </div>

            @if ($opsReady && $viewAsRoot)
                <p class="mt-1 text-xs leading-snug text-red-700">
                    {{ __('Browsing as root. Every toggle is recorded in the activity feed.') }}
                </p>
            @endif
        </div>

        @if (! $opsReady)
            <div class="border-b border-brand-ink/10 px-4 py-2.5 sm:px-5">
                @include('livewire.servers.partials.workspace-ops-not-ready', ['server' => $server])
            </div>
        @else
            @if ($listing)
                @if ($listing->truncated)
                    <div class="border-b border-amber-200/80 bg-amber-50/70 px-5 py-2 text-xs leading-relaxed text-amber-900 sm:px-6">
                        {{ __('Showing :shown of :total entries. Use the filter above or open Manage → Run for a full listing.', ['shown' => count($listing->entries), 'total' => $listing->totalCount]) }}
                    </div>
                @endif

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-brand-ink/10 text-left text-xs">
                        <thead class="bg-brand-sand/30 text-brand-moss">
                            <tr>
                                <th class="px-3 py-1 font-medium">{{ __('Name') }}</th>
                                <th class="px-3 py-1 font-medium">{{ __('Size') }}</th>
                                <th class="px-3 py-1 font-medium">{{ __('Modified') }}</th>
                                <th class="px-3 py-1 font-medium">{{ __('Mode') }}</th>
                                <th class="px-3 py-1 font-medium">{{ __('Owner') }}</th>
                                <th class="px-3 py-1 text-right font-medium">{{ __('Actions') }}</th>
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
                                    <td class="whitespace-nowrap px-3 py-1 font-mono">
                                        {{-- Links before dirs: directory symlinks report isDir()=true but still need → / Follow. --}}
                                        @if ($entry->isLink())
                                            @if ($entry->linkTargetIsDir)
                                                <button type="button" wire:click="openEntry('{{ addslashes($entry->name) }}', '{{ addslashes((string) $entry->linkTarget) }}')" class="inline-flex items-center gap-1.5 text-brand-forest hover:underline">
                                                    <span class="inline-flex h-3.5 w-3.5 shrink-0 items-center justify-center">
                                                        <x-heroicon-o-link class="h-3.5 w-3.5 text-brand-sage" wire:loading.remove wire:target="openEntry('{{ addslashes($entry->name) }}', '{{ addslashes((string) $entry->linkTarget) }}')" />
                                                        <x-spinner size="sm" wire:loading wire:target="openEntry('{{ addslashes($entry->name) }}', '{{ addslashes((string) $entry->linkTarget) }}')" />
                                                    </span>
                                                    <span>{{ $entry->name }}/</span>
                                                </button>
                                            @else
                                                @if ($entryDownloadUrl)
                                                    <a href="{{ $entryDownloadUrl }}" class="inline-flex items-center gap-1.5 text-brand-forest hover:underline">
                                                        <x-heroicon-o-link class="h-3.5 w-3.5 shrink-0 text-brand-sage" />
                                                        <span>{{ $entry->name }}</span>
                                                    </a>
                                                @else
                                                    <span class="inline-flex items-center gap-1.5 text-brand-forest">
                                                        <x-heroicon-o-link class="h-3.5 w-3.5 shrink-0 text-brand-sage" />
                                                        <span>{{ $entry->name }}</span>
                                                    </span>
                                                @endif
                                            @endif
                                            <span class="ml-1 text-brand-moss">→ {{ $entry->linkTarget }}</span>
                                        @elseif ($entry->isDir())
                                            <button type="button" wire:click="openEntry('{{ addslashes($entry->name) }}')" class="inline-flex items-center gap-1.5 text-brand-forest hover:underline">
                                                <span class="inline-flex h-3.5 w-3.5 shrink-0 items-center justify-center">
                                                    <x-heroicon-o-folder class="h-3.5 w-3.5 text-brand-sage" wire:loading.remove wire:target="openEntry('{{ addslashes($entry->name) }}')" />
                                                    <x-spinner size="sm" wire:loading wire:target="openEntry('{{ addslashes($entry->name) }}')" />
                                                </span>
                                                <span>{{ $entry->name }}/</span>
                                            </button>
                                        @else
                                            <button type="button" wire:click="openFile('{{ addslashes($entry->name) }}')" class="inline-flex items-center gap-1.5 text-brand-ink hover:underline">
                                                <span class="inline-flex h-3.5 w-3.5 shrink-0 items-center justify-center">
                                                    <x-heroicon-o-document class="h-3.5 w-3.5 text-brand-mist" wire:loading.remove wire:target="openFile('{{ addslashes($entry->name) }}')" />
                                                    <x-spinner size="sm" wire:loading wire:target="openFile('{{ addslashes($entry->name) }}')" />
                                                </span>
                                                <span>{{ $entry->name }}</span>
                                            </button>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-1 text-brand-moss">{{ $entry->isFile() ? number_format($entry->size) : '—' }}</td>
                                    <td class="whitespace-nowrap px-3 py-1 text-brand-moss" title="{{ \Carbon\Carbon::createFromTimestamp($entry->mtime)->format('Y-m-d H:i:s') }}">{{ \Carbon\Carbon::createFromTimestamp($entry->mtime)->diffForHumans() }}</td>
                                    <td class="whitespace-nowrap px-3 py-1 font-mono text-brand-moss">{{ $entry->mode }}</td>
                                    <td class="whitespace-nowrap px-3 py-1 text-brand-moss">{{ $entry->owner }}:{{ $entry->group }}</td>
                                    <td class="whitespace-nowrap px-3 py-1 text-right">
                                        @if ($entry->isLink() && $entry->linkTargetIsDir)
                                            <button type="button" wire:click="openEntry('{{ addslashes($entry->name) }}', '{{ addslashes((string) $entry->linkTarget) }}')" class="inline-flex items-center gap-1 font-semibold text-brand-forest hover:underline">
                                                <x-spinner size="sm" class="h-3 w-3" wire:loading wire:target="openEntry('{{ addslashes($entry->name) }}', '{{ addslashes((string) $entry->linkTarget) }}')" />
                                                {{ __('Follow') }}
                                            </button>
                                        @elseif ($entry->isFile() || ($entry->isLink() && ! $entry->linkTargetIsDir))
                                            <div class="inline-flex items-center gap-1.5">
                                                <button type="button" wire:click="openFile('{{ addslashes($entry->name) }}')" class="inline-flex items-center gap-1 font-semibold text-brand-ink hover:underline">
                                                    <x-spinner size="sm" class="h-3 w-3" wire:loading wire:target="openFile('{{ addslashes($entry->name) }}')" />
                                                    {{ __('View') }}
                                                </button>
                                                @if ($entryDownloadUrl)
                                                    <a
                                                        href="{{ $entryDownloadUrl }}"
                                                        class="inline-flex items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 py-0.5 text-xs font-semibold text-brand-forest shadow-sm transition-colors hover:bg-brand-sand/40"
                                                    >
                                                        <x-heroicon-o-arrow-down-tray class="h-3.5 w-3.5 shrink-0" />
                                                        {{ __('Download') }}
                                                    </a>
                                                @endif
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-5 py-6 text-center text-brand-moss">{{ __('Empty directory or no matches.') }}</td>
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
            <div class="space-y-3 p-4">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="break-all font-mono text-xs font-semibold text-brand-ink">{{ $viewingPath }}</p>
                        @if ($viewingMime)
                            <p class="mt-0.5 text-xs text-brand-moss">{{ $viewingMime }} · {{ number_format((int) $viewingSize) }} bytes</p>
                        @endif
                    </div>
                    <button type="button" wire:click="closeFileModal" class="shrink-0 text-xs font-semibold text-brand-moss hover:underline">{{ __('Close') }}</button>
                </div>

                @if ($viewingError)
                    <div class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">{{ $viewingError }}</div>
                @elseif ($viewingImageUrl)
                    <div class="flex max-h-[60vh] items-center justify-center overflow-auto rounded-md border border-brand-ink/10 bg-brand-ink/5 p-3">
                        <img src="{{ $viewingImageUrl }}" alt="{{ basename((string) $viewingPath) }}" class="max-h-[56vh] max-w-full object-contain" />
                    </div>
                @elseif ($viewingTruncated)
                    <div class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                        {{ __('File is larger than the inline cap (:cap MB). Use Download.', ['cap' => (int) ($editMaxBytes / 1024 / 1024)]) }}
                    </div>
                @elseif ($viewingIsBinary)
                    <div class="rounded-md border border-brand-ink/10 bg-brand-sand/30 px-3 py-2 text-xs text-brand-moss">
                        {{ __('Binary file — preview unavailable. Use Download.') }}
                    </div>
                @else
                    <pre class="max-h-[65vh] overflow-auto rounded-md border border-brand-ink/10 bg-brand-ink/5 p-3 text-xs leading-relaxed text-brand-ink"><code>{{ $viewingContent }}</code></pre>
                @endif
            </div>
        </x-modal>
    @endif
</x-server-workspace-layout>
