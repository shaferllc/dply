@php
    $rel = str_starts_with($path, $siteRoot) ? substr($path, strlen($siteRoot)) : $path;
    $crumbs = [];
    $accum = $siteRoot;
    foreach (array_filter(explode('/', trim($rel, '/'))) as $segment) {
        $accum .= '/'.$segment;
        $crumbs[] = ['name' => $segment, 'path' => $accum];
    }

    $badge = null;
    if ($isAtomic) {
        if (str_starts_with($path, rtrim($siteRoot, '/').'/releases/')) {
            $badge = ['label' => __('release'), 'tone' => 'amber'];
        } elseif ($path === rtrim($siteRoot, '/').'/shared' || str_starts_with($path, rtrim($siteRoot, '/').'/shared/')) {
            $badge = ['label' => __('shared'), 'tone' => 'sky'];
        }
    }

    // One treatment for every control in the Actions column. Follow, View and
    // Edit were bare text links (in two different colours) sitting next to a
    // bordered Download button — three visual weights for the same class of
    // per-row action.
    $rowActionClass = 'inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40 hover:text-brand-forest';

    $filesDescription = __('Browse the site tree over SSH as :user. Edit text files (≤:edit MB), download anything (≤:dl MB).', [
        'user' => $effectiveLoginUser,
        'edit' => (int) ($editMaxBytes / 1024 / 1024),
        'dl' => (int) ($downloadMaxBytes / 1024 / 1024),
    ]);
@endphp

{{-- Standalone Files page — merged chrome (no floating hero). --}}
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    {{-- Must stay INSIDE the root: Livewire injects wire:id into the first tag
         of the rendered view, so a @vite <link>/<script> above the root steals
         it and orphans this div — no wire:click listeners bind at all. --}}
    @vite(['resources/js/file-browser-editor-lazy.js'])

    {{-- No full-page blocking overlay: navigating a directory is a routine
         action and shouldn't dim and lock the whole page. The listing swaps to
         an inline skeleton instead (see the table below). --}}
    @include('livewire.sites.partials.workspace-breadcrumb-bar', [
        'server' => $server,
        'site' => $site,
        'currentLabel' => __('Files'),
        'currentIcon' => 'folder',
    ])

    <div class="lg:grid lg:grid-cols-12 lg:gap-10">
        @include('livewire.sites.settings.partials.sidebar')

        <div class="min-w-0 lg:col-span-9">
            @if (workspace_surface_coming_soon('site_files'))
                <x-workspace-coming-soon
                    :server="$site->server"
                    icon="heroicon-o-folder"
                    :title="__('Files')"
                    :description="__('Browse and edit your site tree right in the dashboard over SSH — no terminal, no SFTP client — with safe size limits and per-site scoping.')"
                    :eyebrow="__('File browser preview')"
                    :lines="[
                        ['tone' => 'cmd', 'text' => '~ $ ls -la current/'],
                        ['tone' => 'muted', 'text' => 'drwxr-xr-x  app/   config/   public/'],
                        ['tone' => 'muted', 'text' => '-rw-r--r--  .env   composer.json'],
                        ['tone' => 'ok', 'text' => 'scoped to this site · read + edit'],
                    ]"
                    :features="[
                        ['icon' => 'folder-open', 'title' => __('Browse the tree'), 'body' => __('Walk the release and shared directories without opening a terminal.')],
                        ['icon' => 'pencil-square', 'title' => __('Edit in place'), 'body' => __('Tweak a config or template file inline, within safe size limits.')],
                        ['icon' => 'arrow-down-tray', 'title' => __('Download anything'), 'body' => __('Pull a log, a build artifact, or a generated file straight down.')],
                        ['icon' => 'lock-closed', 'title' => __('Scoped & safe'), 'body' => __('Locked to this site\'s directory and its system user — nothing else.')],
                    ]"
                />
            @else
                <section class="dply-card min-w-0 overflow-hidden p-0">
                    <x-workspace-panel-head
                        class="border-b border-brand-ink/10"
                        icon="heroicon-o-folder"
                        :title="__('Files')"
                        :note="$filesDescription"
                    >
                        <x-slot:actions>
                            @if ($badge)
                                <span @class([
                                    'inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] ring-1 ring-inset',
                                    'bg-amber-50 text-amber-800 ring-amber-200' => $badge['tone'] === 'amber',
                                    'bg-sky-50 text-sky-800 ring-sky-200' => $badge['tone'] === 'sky',
                                ])>
                                    {{ $badge['label'] }}
                                </span>
                            @endif
                        </x-slot:actions>
                    </x-workspace-panel-head>

                    {{-- One toolbar row: up · path · filter · identity. The path bar
                         hugs its crumbs instead of stretching flex-1 (which left a
                         wide empty white slab), and the filter rides alongside it
                         rather than dropping to a second right-aligned row. --}}
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-2 border-b border-brand-ink/10 px-4 py-2.5 sm:px-5">
                        <button
                            type="button"
                            wire:click="goUp"
                            @disabled($path === $siteRoot)
                            title="{{ __('Parent directory') }}"
                            aria-label="{{ __('Parent directory') }}"
                            class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-brand-ink/10 bg-white text-brand-moss shadow-sm hover:bg-brand-sand/40 hover:text-brand-ink disabled:cursor-not-allowed disabled:opacity-40"
                        >
                            <x-heroicon-o-arrow-uturn-left class="h-4 w-4" aria-hidden="true" />
                        </button>

                        <nav class="flex min-w-0 flex-wrap items-center gap-0.5 rounded-lg border border-brand-ink/10 bg-white px-1 py-0.5 shadow-sm" aria-label="{{ __('Current path') }}">
                            <button
                                type="button"
                                wire:click="jumpTo('{{ $siteRoot }}')"
                                class="inline-flex h-7 items-center rounded-md px-2 font-mono text-xs text-brand-moss hover:bg-brand-sand/50 hover:text-brand-ink"
                            >{{ basename($siteRoot) }}/</button>
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

                        <div class="ms-auto flex items-center gap-2">
                            <label class="relative block w-40 shrink-0 sm:w-52">
                                <span class="sr-only">{{ __('Filter (glob)') }}</span>
                                <x-heroicon-o-magnifying-glass
                                    class="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-brand-mist"
                                    aria-hidden="true"
                                />
                                <input
                                    type="text"
                                    wire:model.live.debounce.300ms="filter"
                                    placeholder="{{ __('Filter… *.env') }}"
                                    class="h-8 w-full rounded-lg border border-brand-ink/10 bg-white pe-2.5 ps-8 font-mono text-xs leading-none text-brand-ink shadow-sm placeholder:text-brand-mist focus:border-brand-forest focus:outline-none focus:ring-1 focus:ring-brand-forest"
                                >
                            </label>

                            <span
                                class="inline-flex h-8 shrink-0 items-center gap-1.5 rounded-lg bg-brand-sand/50 px-2.5 font-mono text-xs font-semibold text-brand-moss ring-1 ring-inset ring-brand-ink/10"
                                title="{{ __('Browsing as :user', ['user' => $effectiveLoginUser]) }}"
                            >
                                <x-heroicon-o-user class="h-3.5 w-3.5 shrink-0 opacity-70" aria-hidden="true" />
                                <span class="sr-only">{{ __('Running as') }}</span>
                                {{ $effectiveLoginUser }}
                            </span>
                        </div>
                    </div>

                    {{-- Inline swap: the row list dims to a skeleton while a
                         directory change is in flight, so the chrome (path bar,
                         filter, actions) stays live and clickable. --}}
                    <div class="hidden" wire:loading.class.remove="hidden" wire:target="openEntry, jumpTo, goUp, filter">
                        <div class="divide-y divide-brand-ink/10 px-5 py-1 sm:px-6" aria-busy="true" aria-live="polite">
                            <span class="sr-only">{{ __('Loading…') }}</span>
                            @for ($i = 0; $i < 6; $i++)
                                <div class="flex items-center gap-4 py-2.5" aria-hidden="true">
                                    <span class="h-3 animate-pulse rounded bg-brand-ink/15" style="width: {{ [34, 22, 41, 28, 37, 25][$i] }}%"></span>
                                    <span class="ml-auto h-3 w-10 animate-pulse rounded bg-brand-ink/10"></span>
                                    <span class="h-3 w-20 animate-pulse rounded bg-brand-ink/10"></span>
                                    <span class="h-3 w-16 animate-pulse rounded bg-brand-ink/10"></span>
                                </div>
                            @endfor
                        </div>
                    </div>

                    @if ($listing)
                    <div wire:loading.class="hidden" wire:target="openEntry, jumpTo, goUp, filter">
                        @if ($listing->truncated)
                            <div class="border-b border-amber-200/80 bg-amber-50/70 px-5 py-3 text-sm text-amber-900 sm:px-6">
                                {{ __('Showing :shown of :total entries. Narrow the filter or use Manage → Run for the full listing.', ['shown' => count($listing->entries), 'total' => $listing->totalCount]) }}
                            </div>
                        @endif

                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-brand-ink/10 text-left text-xs">
                                <thead class="bg-brand-sand/30 text-brand-moss">
                                    <tr>
                                        {{-- w-full here + max-w-0 on the cell makes Name the flexible column:
                                             it absorbs the slack and truncates instead of forcing the table
                                             wider than the card (which pushed Actions off the right edge). --}}
                                        <th class="w-full px-4 py-2 font-medium">{{ __('Name') }}</th>
                                        <th class="px-4 py-2 font-medium">{{ __('Size') }}</th>
                                        <th class="px-4 py-2 font-medium">{{ __('Modified') }}</th>
                                        <th class="px-4 py-2 font-medium">{{ __('Mode') }}</th>
                                        <th class="px-4 py-2 text-right font-medium">{{ __('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-brand-ink/10 text-brand-ink">
                                    @forelse ($listing->entries as $entry)
                                        @php
                                            try {
                                                $entryDownloadPath = \App\Support\Servers\FileBrowserPathPolicy::join($path, $entry->name);
                                                $entryDownloadUrl = route('sites.files.download', [
                                                    'server' => $server,
                                                    'site' => $site,
                                                    'path' => $entryDownloadPath,
                                                ]);
                                            } catch (\InvalidArgumentException) {
                                                $entryDownloadUrl = null;
                                            }

                                            // Symlink targets are absolute and nearly always inside the site
                                            // root, so the head duplicates the crumb bar while the tail is the
                                            // part you actually want. Printed in full they widened the Name
                                            // column enough to push Actions off the edge of the card. Show the
                                            // meaningful tail; the full path is on the title tooltip.
                                            $linkFull = (string) $entry->linkTarget;
                                            $linkDisplay = $linkFull;
                                            $siteRootTrimmed = rtrim($siteRoot, '/');
                                            if (str_starts_with($linkDisplay, $siteRootTrimmed.'/')) {
                                                $linkDisplay = '…/'.substr($linkDisplay, strlen($siteRootTrimmed) + 1);
                                            }
                                            if (mb_strlen($linkDisplay) > 28) {
                                                // Trim from the left: the tail identifies the target, the head
                                                // is boilerplate, so a normal right-side truncate would cut
                                                // away the only informative half.
                                                $linkDisplay = '…'.mb_substr($linkDisplay, -27);
                                            }
                                        @endphp
                                        <tr class="hover:bg-brand-sand/20">
                                            <td class="max-w-0 px-4 py-2 font-mono">
                                                {{-- Links before dirs: FileBrowserEntry::isDir() is true for
                                                     directory symlinks, and we still want the → target + Follow UX. --}}
                                                @if ($entry->isLink())
                                                    {{-- Name and → target share one flex row. The name is an inline-flex
                                                         (icon + label) whose baseline comes from the icon, not the text, so
                                                         a plain inline target span next to it rendered a couple of pixels
                                                         low. items-center puts both on the same centre line. --}}
                                                    <span class="flex min-w-0 items-center gap-2">
                                                        @if ($entry->linkTargetIsDir)
                                                            <button type="button" wire:click="openEntry('{{ addslashes($entry->name) }}', '{{ addslashes((string) $entry->linkTarget) }}')" class="inline-flex min-w-0 shrink-0 items-center gap-2 text-brand-forest hover:underline" title="{{ $entry->name }}">
                                                                <x-heroicon-o-link class="h-4 w-4 shrink-0 text-brand-sage" />
                                                                <span class="truncate">{{ $entry->name }}/</span>
                                                            </button>
                                                        @else
                                                            @if ($entryDownloadUrl)
                                                                <a href="{{ $entryDownloadUrl }}" class="inline-flex min-w-0 shrink-0 items-center gap-2 text-brand-forest hover:underline" title="{{ $entry->name }}">
                                                                    <x-heroicon-o-link class="h-4 w-4 shrink-0 text-brand-sage" />
                                                                    <span class="truncate">{{ $entry->name }}</span>
                                                                </a>
                                                            @else
                                                                <span class="inline-flex min-w-0 shrink-0 items-center gap-2 text-brand-forest" title="{{ $entry->name }}">
                                                                    <x-heroicon-o-link class="h-4 w-4 shrink-0 text-brand-sage" />
                                                                    <span class="truncate">{{ $entry->name }}</span>
                                                                </span>
                                                            @endif
                                                        @endif
                                                        {{-- Styled tooltip rather than a native title=: the bubble is
                                                             teleported to <body> so the card's overflow-x-auto can't clip
                                                             it, and it shows instantly instead of after the browser's
                                                             ~1s title delay. Only symlink rows get an Alpine root —
                                                             there are a handful per directory, whereas wrapping every
                                                             name in a listing of up to 2,000 entries would not be free. --}}
                                                        <x-tooltip :label="$linkFull" class="min-w-0">
                                                            <span class="truncate text-brand-moss decoration-brand-mist decoration-dotted underline-offset-4 hover:cursor-help hover:text-brand-ink hover:underline">→ {{ $linkDisplay }}</span>
                                                        </x-tooltip>
                                                    </span>
                                                @elseif ($entry->isDir())
                                                    <button type="button" wire:click="openEntry('{{ addslashes($entry->name) }}')" class="flex min-w-0 items-center gap-2 text-brand-forest hover:underline" title="{{ $entry->name }}">
                                                        <x-heroicon-o-folder class="h-4 w-4 shrink-0 text-brand-sage" />
                                                        <span class="truncate">{{ $entry->name }}/</span>
                                                    </button>
                                                @else
                                                    <button type="button" wire:click="openFile('{{ addslashes($entry->name) }}')" class="flex min-w-0 items-center gap-2 text-brand-ink hover:underline" title="{{ $entry->name }}">
                                                        <x-heroicon-o-document class="h-4 w-4 shrink-0 text-brand-mist" />
                                                        <span class="truncate">{{ $entry->name }}</span>
                                                    </button>
                                                @endif
                                            </td>
                                            <td class="whitespace-nowrap px-4 py-2 text-brand-moss">{{ $entry->isFile() ? number_format($entry->size) : '—' }}</td>
                                            <td class="whitespace-nowrap px-4 py-2 text-brand-moss" title="{{ \Carbon\Carbon::createFromTimestamp($entry->mtime)->format('Y-m-d H:i:s') }}">{{ \Carbon\Carbon::createFromTimestamp($entry->mtime)->diffForHumans() }}</td>
                                            <td class="whitespace-nowrap px-4 py-2 font-mono text-brand-moss">{{ $entry->mode }}</td>
                                            <td class="whitespace-nowrap px-4 py-2 text-right">
                                                @if ($entry->isLink() && $entry->linkTargetIsDir)
                                                    <button type="button" wire:click="openEntry('{{ addslashes($entry->name) }}', '{{ addslashes((string) $entry->linkTarget) }}')" class="{{ $rowActionClass }}">
                                                        <x-heroicon-o-arrow-right class="h-3.5 w-3.5 shrink-0 opacity-70" aria-hidden="true" />
                                                        {{ __('Follow') }}
                                                    </button>
                                                @elseif ($entry->isFile() || ($entry->isLink() && ! $entry->linkTargetIsDir))
                                                    <div class="inline-flex items-center gap-1.5">
                                                        <button type="button" wire:click="openFile('{{ addslashes($entry->name) }}')" class="{{ $rowActionClass }}">
                                                            <x-heroicon-o-eye class="h-3.5 w-3.5 shrink-0 opacity-70" aria-hidden="true" />
                                                            {{ __('View') }}
                                                        </button>
                                                        <button type="button" wire:click="startEdit('{{ addslashes($entry->name) }}')" class="{{ $rowActionClass }}">
                                                            <x-heroicon-o-pencil-square class="h-3.5 w-3.5 shrink-0 opacity-70" aria-hidden="true" />
                                                            {{ __('Edit') }}
                                                        </button>
                                                        @if ($entryDownloadUrl)
                                                            <a href="{{ $entryDownloadUrl }}" class="{{ $rowActionClass }}">
                                                                <x-heroicon-o-arrow-down-tray class="h-3.5 w-3.5 shrink-0 opacity-70" aria-hidden="true" />
                                                                {{ __('Download') }}
                                                            </a>
                                                        @endif
                                                    </div>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="5" class="px-6 py-10 text-center text-brand-moss">{{ __('Empty directory or no matches.') }}</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                    @endif
                </section>
            @endif
        </div>
    </div>

    {{-- Opening a file is a real SSH round-trip (stat + mime sniff + cat), so
         View/Edit land a second or two behind the click. Instead of leaving the
         row inert, the destination modal appears immediately as a skeleton and
         fills in when the payload arrives.

         Scoped to openFile/startEdit only — directory navigation deliberately
         has no overlay and swaps the listing to an inline skeleton instead. --}}
    <div
        class="fixed inset-0 z-[90] hidden"
        wire:loading.class.remove="hidden"
        wire:target="openFile, startEdit"
        role="status"
        aria-live="polite"
    >
        {{-- bg-black/50 to match x-modal's scrim exactly — the skeleton is
             standing in for that modal, so it shouldn't read as a different
             surface for the second it's up. --}}
        <div class="absolute inset-0 bg-black/50" aria-hidden="true"></div>
        <div class="relative flex h-full items-center justify-center overflow-y-auto px-4 py-6">
            <div class="dply-modal-panel w-full max-w-4xl space-y-4 p-6 shadow-xl">
                <span class="sr-only">{{ __('Reading the file over SSH…') }}</span>

                <div class="flex items-start justify-between gap-3" aria-hidden="true">
                    <div class="min-w-0 flex-1">
                        <p class="text-xs uppercase tracking-wide text-brand-moss">{{ __('Opening') }}</p>
                        <span class="mt-1.5 block h-4 w-64 max-w-full animate-pulse rounded bg-brand-ink/15"></span>
                        <span class="mt-2 block h-3 w-40 max-w-full animate-pulse rounded bg-brand-ink/10"></span>
                    </div>
                    <span class="inline-flex shrink-0 items-center gap-2 text-xs text-brand-moss">
                        <x-spinner size="sm" variant="muted" />
                        {{ __('Reading over SSH…') }}
                    </span>
                </div>

                <div class="space-y-2.5 rounded-md border border-brand-ink/10 bg-brand-ink/5 p-3" aria-hidden="true">
                    @foreach ([92, 68, 80, 44, 88, 72, 35, 84, 60, 76, 50, 66] as $width)
                        <span class="block h-2.5 animate-pulse rounded bg-brand-ink/10" style="width: {{ $width }}%"></span>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    {{-- View modal --}}
    @if ($showViewModal)
        @php
            $viewingIsImage = $viewingMime && str_starts_with($viewingMime, 'image/');
            $viewingImagePreviewable = $viewingIsImage && $viewingSize !== null && $viewingSize <= $downloadMaxBytes;
            $viewingImageUrl = null;
            if ($viewingImagePreviewable && $viewingPath !== null) {
                try {
                    $viewingImageUrl = route('sites.files.download', [
                        'server' => $server,
                        'site' => $site,
                        'path' => $viewingPath,
                    ]);
                } catch (\InvalidArgumentException) {
                    $viewingImageUrl = null;
                }
            }
        @endphp
        <x-modal name="site-file-view" :show="true" max-width="4xl">
            <div class="space-y-4 p-6">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-xs uppercase tracking-wide text-brand-moss">{{ __('File') }}</p>
                        <p class="break-all font-mono text-sm font-semibold">{{ $viewingPath }}</p>
                        @if ($viewingMime)
                            <p class="mt-1 text-xs text-brand-moss">{{ $viewingMime }} · {{ number_format((int) $viewingSize) }} bytes</p>
                        @endif
                    </div>
                    <button type="button" wire:click="closeViewModal" class="text-sm text-brand-moss hover:underline">{{ __('Close') }}</button>
                </div>

                @if ($viewingError)
                    <div class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{{ $viewingError }}</div>
                @elseif ($viewingImageUrl)
                    <div class="flex max-h-[60vh] items-center justify-center overflow-auto rounded-md border border-brand-ink/10 bg-brand-ink/5 p-3">
                        <img src="{{ $viewingImageUrl }}" alt="{{ basename((string) $viewingPath) }}" class="max-h-[56vh] max-w-full object-contain" />
                    </div>
                @elseif ($viewingTruncated)
                    <div class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                        {{ __('File is larger than the inline cap. Use Download.') }}
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

    {{-- Edit modal --}}
    @if ($showEditModal)
        <x-modal name="site-file-edit" :show="true" wire:model="showEditModal" max-width="5xl">
            <div class="space-y-4 p-6">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-xs uppercase tracking-wide text-brand-moss">{{ __('Editing') }}</p>
                        <p class="break-all font-mono text-sm font-semibold">{{ $editingPath }}</p>
                        @if ($editingMime)
                            <p class="mt-1 text-xs text-brand-moss">{{ $editingMime }} · {{ number_format((int) $editingSize) }} bytes</p>
                        @endif
                    </div>
                    <button type="button" wire:click="closeEditModal" class="text-sm text-brand-moss hover:underline">{{ __('Cancel') }}</button>
                </div>

                @if ($editingInsideReleases)
                    <div class="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                        {{ __('This file lives inside a release directory. The next deploy will create a new release dir and this change will be lost. To make a durable change, edit the version under shared/ or in your repo.') }}
                    </div>
                @endif

                <div
                    x-data="{
                        editor: null,
                        async init() {
                            const mountEditor = window.dplyEnsureFileBrowserEditor
                                ? await window.dplyEnsureFileBrowserEditor()
                                : null;
                            if (! mountEditor) return;
                            this.editor = mountEditor(this.$refs.editorMount, {
                                content: this.$wire.editingContent ?? '',
                                mime: this.$wire.editingMime ?? '',
                                path: this.$wire.editingPath ?? '',
                                onChange: (val) => { this.$wire.set('editingContent', val, true); },
                            });
                        },
                    }"
                    x-init="init()"
                    x-on:livewire-update.window="if (editor) editor.setContent($wire.editingContent ?? '')"
                    class="rounded-md border border-brand-ink/15"
                >
                    <div x-ref="editorMount" class="min-h-[40vh]">
                        <textarea
                            x-show="!editor"
                            wire:model="editingContent"
                            class="block w-full min-h-[40vh] font-mono text-xs leading-relaxed p-3 focus:outline-none"
                        ></textarea>
                    </div>
                </div>

                @if ($pendingReleaseWarning)
                    <div class="rounded-md border border-amber-300 bg-amber-50 px-3 py-3 text-sm text-amber-900">
                        <p class="font-semibold">{{ __('Saving inside a release directory') }}</p>
                        <p class="mt-1 text-xs">{{ __('Confirm you want to save here. The next deploy will create a new release directory and this change will be wiped.') }}</p>
                        <div class="mt-3 flex gap-2">
                            <x-primary-button
                                size="sm"
                                type="button"
                                wire:click="saveEdit(true)"
                                wire:target="saveEdit"
                                wire:loading.attr="disabled"
                                class="bg-amber-600 hover:bg-amber-700"
                            >
                                <span wire:loading.remove wire:target="saveEdit">{{ __('Save anyway') }}</span>
                                <span class="inline-flex items-center gap-2" wire:loading wire:target="saveEdit">
                                    <x-spinner size="sm" variant="cream" />
                                    {{ __('Saving…') }}
                                </span>
                            </x-primary-button>
                            <x-secondary-button size="xs" type="button" wire:click="$set('pendingReleaseWarning', false)">{{ __('Back to editor') }}</x-secondary-button>
                        </div>
                    </div>
                @else
                    {{-- Writing is another SSH round-trip; without this the button
                         just sat there and invited a second click. --}}
                    <div class="flex justify-end gap-2">
                        <x-secondary-button size="xs" type="button" wire:click="closeEditModal">{{ __('Cancel') }}</x-secondary-button>
                        <x-primary-button
                            size="sm"
                            type="button"
                            wire:click="saveEdit"
                            wire:target="saveEdit"
                            wire:loading.attr="disabled"
                        >
                            <span wire:loading.remove wire:target="saveEdit">{{ __('Save') }}</span>
                            <span class="inline-flex items-center gap-2" wire:loading wire:target="saveEdit">
                                <x-spinner size="sm" variant="cream" />
                                {{ __('Saving…') }}
                            </span>
                        </x-primary-button>
                    </div>
                @endif
            </div>
        </x-modal>
    @endif

    {{-- Conflict modal --}}
    @if ($showConflictModal)
        <x-modal name="site-file-conflict" :show="true" wire:model="showConflictModal" max-width="lg">
            <div class="space-y-3 p-6">
                <p class="text-sm font-semibold text-brand-ink">{{ __('File changed on disk') }}</p>
                <p class="text-sm text-brand-moss">{{ $conflictMessage }}</p>
                <div class="flex justify-end">
                    <x-secondary-button size="xs" type="button" wire:click="closeConflictModal">{{ __('Close') }}</x-secondary-button>
                </div>
            </div>
        </x-modal>
    @endif
</div>
