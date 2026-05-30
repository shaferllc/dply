@php
    $card = 'dply-card overflow-hidden';
    $btnSecondary = 'inline-flex items-center justify-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold uppercase tracking-wide text-brand-ink shadow-sm hover:bg-brand-sand/50 transition-colors disabled:cursor-not-allowed disabled:opacity-50';

    $crumbs = [];
    $accum = '';
    foreach (explode('/', trim($path, '/')) as $segment) {
        if ($segment === '') {
            continue;
        }
        $accum .= '/'.$segment;
        $crumbs[] = ['name' => $segment, 'path' => $accum];
    }
@endphp

<x-server-workspace-layout
    :server="$server"
    active="files"
    :title="__('Files')"
    :description="__('Read-only filesystem browser over SSH. View text or image previews and download files (up to :mb MB).', ['mb' => (int) ($downloadMaxBytes / 1024 / 1024)])"
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
    @if (! $opsReady)
        @include('livewire.servers.partials.workspace-ops-not-ready', ['server' => $server])
    @else
        <div class="{{ $card }} p-4 sm:p-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-xs uppercase tracking-wide text-brand-moss">{{ __('Running as') }}</p>
                    <p class="font-mono text-sm font-semibold text-brand-ink">{{ $effectiveLoginUser }}</p>
                </div>

                @if ($canViewAsRoot)
                    <button type="button" wire:click="toggleViewAsRoot" class="{{ $btnSecondary }} {{ $viewAsRoot ? 'border-red-300 bg-red-50 text-red-700 hover:bg-red-100' : '' }}">
                        {{ $viewAsRoot ? __('Viewing as root — click to drop') : __('View as root') }}
                    </button>
                @endif
            </div>

            @if ($viewAsRoot)
                <p class="mt-3 rounded-lg border border-red-200 bg-red-50/60 px-3 py-2 text-xs text-red-700">
                    {{ __('Browsing as root. Every toggle is recorded in the activity feed.') }}
                </p>
            @endif

            <div class="mt-4 flex flex-wrap items-center gap-2 text-xs text-brand-moss">
                <span class="font-semibold uppercase tracking-wide">{{ __('Quick jumps') }}:</span>
                @foreach ($quickJumps as $qj)
                    <button type="button" wire:click="jumpTo('{{ $qj }}')" class="rounded-md border border-brand-ink/10 bg-brand-sand/30 px-2 py-0.5 font-mono text-brand-ink hover:bg-brand-sand/60">{{ $qj }}</button>
                @endforeach
            </div>

            <div class="mt-4 flex flex-wrap items-center gap-2 text-xs">
                <button type="button" wire:click="jumpTo('/')" class="rounded-md bg-brand-ink/5 px-2 py-1 font-mono hover:bg-brand-ink/10">/</button>
                @foreach ($crumbs as $i => $crumb)
                    <span class="text-brand-moss">/</span>
                    @if ($i === count($crumbs) - 1)
                        <span class="rounded-md bg-brand-ink/10 px-2 py-1 font-mono font-semibold text-brand-ink">{{ $crumb['name'] }}</span>
                    @else
                        <button type="button" wire:click="jumpTo('{{ $crumb['path'] }}')" class="rounded-md bg-brand-ink/5 px-2 py-1 font-mono hover:bg-brand-ink/10">{{ $crumb['name'] }}</button>
                    @endif
                @endforeach
                <button type="button" wire:click="goUp" class="ml-2 rounded-md border border-brand-ink/15 px-2 py-1 font-semibold hover:bg-brand-sand/40">{{ __('Up') }}</button>
            </div>

            <div class="mt-4">
                <label class="block text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Filter (glob)') }}</label>
                <input type="text" wire:model.live.debounce.300ms="filter" placeholder="*.conf" class="mt-1 w-full max-w-md rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-sm font-mono focus:border-brand-forest focus:outline-none focus:ring-1 focus:ring-brand-forest">
            </div>
        </div>

        @if ($listing)
            @if ($listing->truncated)
                <div class="rounded-2xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    {{ __('Showing :shown of :total entries. Use the filter above or open Manage → Run for a full listing.', ['shown' => count($listing->entries), 'total' => $listing->totalCount]) }}
                </div>
            @endif

            <div class="{{ $card }} mt-4">
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
                                        @if ($entry->isDir())
                                            <button type="button" wire:click="openEntry('{{ addslashes($entry->name) }}')" class="inline-flex items-center gap-2 text-brand-forest hover:underline">
                                                <x-heroicon-o-folder class="h-4 w-4 shrink-0 text-brand-sage" />
                                                <span>{{ $entry->name }}/</span>
                                            </button>
                                        @elseif ($entry->isLink())
                                            @if ($entry->linkTargetIsDir)
                                                <button type="button" wire:click="openEntry('{{ addslashes($entry->name) }}')" class="inline-flex items-center gap-2 text-brand-forest hover:underline">
                                                    <x-heroicon-o-link class="h-4 w-4 shrink-0 text-brand-sage" />
                                                    <span>{{ $entry->name }}</span>
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
                                        @if ($entry->isFile() || ($entry->isLink() && ! $entry->linkTargetIsDir))
                                            <div class="inline-flex items-center gap-2">
                                                <button type="button" wire:click="openFile('{{ addslashes($entry->name) }}')" class="font-semibold text-brand-ink hover:underline">{{ __('View') }}</button>
                                                @if ($entryDownloadUrl)
                                                    <a
                                                        href="{{ $entryDownloadUrl }}"
                                                        class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-forest shadow-sm transition-colors hover:bg-brand-sand/40"
                                                    >
                                                        <x-heroicon-o-arrow-down-tray class="h-3.5 w-3.5" />
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
            </div>
        @endif

        @if ($showFileModal)
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
    @endif
</x-server-workspace-layout>
