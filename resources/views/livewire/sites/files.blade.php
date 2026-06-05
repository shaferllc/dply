@php
    $card = 'dply-card overflow-hidden';
    $btnPrimary = 'inline-flex items-center justify-center gap-2 rounded-lg bg-brand-ink px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-brand-cream shadow-sm hover:bg-brand-forest transition-colors disabled:cursor-not-allowed disabled:opacity-50';
    $btnSecondary = 'inline-flex items-center justify-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold uppercase tracking-wide text-brand-ink shadow-sm hover:bg-brand-sand/50 transition-colors';

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
@endphp

@vite(['resources/js/file-browser-editor-lazy.js'])

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div
        wire:loading.flex
        wire:target="openFile, openEntry, startEdit, saveEdit, jumpTo, goUp"
        class="fixed inset-0 z-[60] hidden items-center justify-center bg-brand-ink/40 backdrop-blur-sm"
    >
        <div class="flex items-center gap-3 rounded-2xl bg-white px-5 py-4 shadow-xl ring-1 ring-brand-ink/10">
            <x-spinner variant="forest" />
            <span class="text-sm font-medium text-brand-ink">{{ __('Loading…') }}</span>
        </div>
    </div>
    @include('livewire.sites.partials.workspace-breadcrumb-bar', [
        'server' => $server,
        'site' => $site,
        'currentLabel' => __('Files'),
        'currentIcon' => 'folder',
    ])

    <div class="space-y-6 lg:grid lg:grid-cols-12 lg:gap-10 lg:space-y-0">
        @include('livewire.sites.settings.partials.sidebar')

        <main class="min-w-0 space-y-6 lg:col-span-9">
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
            <x-page-header
                :title="__('Files')"
                :description="__('Browse the site tree over SSH as :user. Edit text files (≤:edit MB), download anything (≤:dl MB).', ['user' => $effectiveLoginUser, 'edit' => (int) ($editMaxBytes / 1024 / 1024), 'dl' => (int) ($downloadMaxBytes / 1024 / 1024)])"
                :show-documentation="false"
                flush
                compact
            />

    <div class="{{ $card }} p-4 sm:p-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <p class="text-xs uppercase tracking-wide text-brand-moss">{{ __('Running as') }}</p>
                <p class="font-mono text-sm font-semibold text-brand-ink">{{ $effectiveLoginUser }}</p>
            </div>
            @if ($badge)
                <span class="rounded-full bg-{{ $badge['tone'] }}-100 px-3 py-1 text-xs font-semibold text-{{ $badge['tone'] }}-800">
                    {{ $badge['label'] }}
                </span>
            @endif
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-2 text-xs">
            <button type="button" wire:click="jumpTo('{{ $siteRoot }}')" class="rounded-md bg-brand-ink/5 px-2 py-1 font-mono hover:bg-brand-ink/10">{{ basename($siteRoot) }}/</button>
            @foreach ($crumbs as $i => $crumb)
                <span class="text-brand-moss">/</span>
                @if ($i === count($crumbs) - 1)
                    <span class="rounded-md bg-brand-ink/10 px-2 py-1 font-mono font-semibold text-brand-ink">{{ $crumb['name'] }}</span>
                @else
                    <button type="button" wire:click="jumpTo('{{ $crumb['path'] }}')" class="rounded-md bg-brand-ink/5 px-2 py-1 font-mono hover:bg-brand-ink/10">{{ $crumb['name'] }}</button>
                @endif
            @endforeach
            <button type="button" wire:click="goUp" @disabled($path === $siteRoot) class="ml-2 rounded-md border border-brand-ink/15 px-2 py-1 font-semibold hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-40">{{ __('Up') }}</button>
        </div>

        <div class="mt-4">
            <label class="block text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Filter (glob)') }}</label>
            <input type="text" wire:model.live.debounce.300ms="filter" placeholder="*.env" class="mt-1 w-full max-w-md rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-sm font-mono focus:border-brand-forest focus:outline-none focus:ring-1 focus:ring-brand-forest">
        </div>
    </div>

    @if ($listing)
        @if ($listing->truncated)
            <div class="mt-4 rounded-2xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                {{ __('Showing :shown of :total entries. Narrow the filter or use Manage → Run for the full listing.', ['shown' => count($listing->entries), 'total' => $listing->totalCount]) }}
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
                                <td class="whitespace-nowrap px-4 py-2 text-right">
                                    @if ($entry->isFile() || ($entry->isLink() && ! $entry->linkTargetIsDir))
                                        <div class="inline-flex items-center gap-3">
                                            <button type="button" wire:click="openFile('{{ addslashes($entry->name) }}')" class="font-semibold text-brand-ink hover:underline">{{ __('View') }}</button>
                                            <button type="button" wire:click="startEdit('{{ addslashes($entry->name) }}')" class="font-semibold text-brand-ink hover:underline">{{ __('Edit') }}</button>
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
                            <tr><td colspan="5" class="px-6 py-10 text-center text-brand-moss">{{ __('Empty directory or no matches.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
            @endif
        </main>
    </div>

    {{-- View modal --}}
    @if ($showViewModal)
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
                            <button type="button" wire:click="saveEdit(true)" class="{{ $btnPrimary }} bg-amber-600 hover:bg-amber-700">{{ __('Save anyway') }}</button>
                            <button type="button" wire:click="$set('pendingReleaseWarning', false)" class="{{ $btnSecondary }}">{{ __('Back to editor') }}</button>
                        </div>
                    </div>
                @else
                    <div class="flex justify-end gap-2">
                        <button type="button" wire:click="closeEditModal" class="{{ $btnSecondary }}">{{ __('Cancel') }}</button>
                        <button type="button" wire:click="saveEdit" class="{{ $btnPrimary }}">{{ __('Save') }}</button>
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
                    <button type="button" wire:click="closeConflictModal" class="{{ $btnSecondary }}">{{ __('Close') }}</button>
                </div>
            </div>
        </x-modal>
    @endif
</div>
