@php
    $actionSecondary = 'inline-flex items-center justify-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/50 transition-colors';
    $actionPrimary = 'inline-flex items-center justify-center gap-2 rounded-lg bg-brand-ink px-5 py-2.5 text-sm font-semibold text-brand-cream shadow-sm hover:bg-brand-forest transition-colors disabled:cursor-not-allowed disabled:opacity-50 w-full sm:w-auto';
    $canEdit = auth()->user()->can('update', $site);
    // Only nginx has before/main/after snippet layers; every other engine edits
    // a single managed file, so the Layered/Full-file toggle + layer pipeline are
    // hidden for them (see WebserverConfig::supportsLayeredSnippets()).
    $supportsLayers = $site->webserver() === 'nginx';
    $isNginxLayeredUi = $supportsLayers && $mode === \App\Models\SiteWebserverConfigProfile::MODE_LAYERED;
    $showNginxLayerPipeline = $isNginxLayeredUi;
    $editingPath = $mode === \App\Models\SiteWebserverConfigProfile::MODE_FULL_OVERRIDE
        ? $config_paths['main_vhost']
        : match ($active_layer) {
            'before' => $config_paths['before_layer'] ?? $config_paths['main_vhost'],
            'after' => $config_paths['after_layer'] ?? $config_paths['main_vhost'],
            'main' => $config_paths['main_vhost'],
            default => $config_paths['main_vhost'],
        };
    $tabBtn = 'px-3 py-1.5 rounded-md text-xs font-semibold transition-colors';
    $tabActive = 'bg-brand-ink text-brand-cream';
    $tabIdle = 'text-brand-moss hover:text-brand-ink hover:bg-brand-sand/60';
    $sidebarServerTargetLayer = $mode === \App\Models\SiteWebserverConfigProfile::MODE_FULL_OVERRIDE ? 'full' : 'main';
    $runtimeMode = $site->runtimeTargetMode();
    $runtimeTarget = $site->runtimeTarget();
    $runtimePublication = is_array($runtimeTarget['publication'] ?? null) ? $runtimeTarget['publication'] : [];
    $resourceNoun = $runtimeMode === 'vm' ? __('Site') : __('App');
    $resourcePlural = $runtimeMode === 'vm' ? __('sites') : __('apps');
    $settingsSidebarItems = \App\Support\SiteSettingsSidebar::items($site, $server);
    $section = 'webserver-config';
    $routingTab = 'domains';
    $laravel_tab = 'commands';
@endphp

<div class="max-w-7xl mx-auto px-4 py-8 sm:px-6 lg:px-8">
    @include('livewire.sites.partials.workspace-breadcrumb-bar', [
        'server' => $server,
        'site' => $site,
        'currentLabel' => __('Web server config'),
        'currentIcon' => 'globe-alt',
    ])

    <div class="space-y-6 lg:grid lg:grid-cols-12 lg:gap-10 lg:space-y-0">
        @include('livewire.sites.settings.partials.sidebar')

        <main class="min-w-0 space-y-6 lg:col-span-9">
    <x-hero-card
        :eyebrow="__('Settings')"
        :title="__('Web server config')"
        :description="__('Edit, validate, and apply your :engine virtual host configuration.', ['engine' => $config_paths['engine_label']])"
        icon="globe-alt"
    />

    @if ($core_changed_warning)
        <div class="mb-6 dply-card overflow-hidden">
            <div class="flex items-start gap-3 bg-amber-50/60 px-6 py-5 sm:px-7">
                <x-icon-badge tone="amber">
                    <x-heroicon-o-exclamation-triangle class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Out of sync') }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-amber-950">{{ __('Managed core changed') }}</h2>
                    <p class="mt-1 text-sm leading-relaxed text-amber-900">{{ __('Site settings changed the managed core since the last apply. Review the diff before applying.') }}</p>
                </div>
            </div>
        </div>
    @endif

    @if ($health_hint)
        <div class="mb-6 dply-card overflow-hidden">
            <div class="flex items-start gap-3 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <x-icon-badge>
                    <x-heroicon-o-information-circle class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Health') }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Webserver status') }}</h2>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ $health_hint }}</p>
                </div>
            </div>
        </div>
    @endif

    {{-- 75% / 25%: plain 4-column grid, main spans 3, pipeline spans 1 (md+). --}}
    <div class="grid grid-cols-1 gap-8 md:grid-cols-4 md:items-start md:gap-x-6 lg:gap-x-8">
        <div class="min-w-0 space-y-5 md:col-span-3">
            <div
                class="dply-card overflow-hidden min-w-0"
                x-data="{
                    insertAtCursor(text, block = true) {
                        const el = $refs.cfgEditor;
                        if (! el) return;
                        el.focus();
                        const start = el.selectionStart ?? el.value.length;
                        const before = el.value.slice(0, start);
                        // Block snippets get blank-line padding; inline tokens are spliced as-is.
                        const lead = (block && before && ! before.endsWith('\n')) ? '\n' : '';
                        const trail = block ? (text.endsWith('\n') ? '' : '\n') : '';
                        const chunk = lead + text + trail;
                        // execCommand('insertText') keeps the native undo/redo stack intact
                        // (assigning el.value directly wipes it). Fall back if unsupported.
                        let ok = false;
                        try { ok = document.execCommand('insertText', false, chunk); } catch (e) { ok = false; }
                        if (! ok) {
                            const end = el.selectionEnd ?? start;
                            el.value = before + chunk + el.value.slice(end);
                            const caret = (before + chunk).length;
                            el.setSelectionRange(caret, caret);
                            el.dispatchEvent(new Event('input', { bubbles: true }));
                        }
                    },
                    saveShortcut(e) {
                        // ⌘/Ctrl+S → save draft (undo/redo are handled natively by the
                        // textarea now that inserts go through execCommand). Only when an
                        // editable editor is present (read-only viewers have no cfgEditor ref).
                        if (! $refs.cfgEditor) return;
                        if ((e.metaKey || e.ctrlKey) && ! e.shiftKey && e.key.toLowerCase() === 's') {
                            e.preventDefault();
                            $wire.saveDraft();
                        }
                    }
                }"
                x-on:insert-snippet-text.window="insertAtCursor($event.detail.text, $event.detail.block ?? true)"
                x-on:keydown.window="saveShortcut($event)"
            >
                <div class="flex flex-col gap-3 border-b border-brand-ink/10 bg-brand-cream/40 px-4 py-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
                    <div class="flex flex-wrap items-center gap-2 sm:gap-3">
                        <div class="flex flex-wrap gap-1 rounded-lg bg-white/80 p-0.5 border border-brand-ink/10">
                            <button type="button" wire:click="$set('content_tab', 'edit')" class="{{ $tabBtn }} {{ $content_tab === 'edit' ? $tabActive : $tabIdle }}">{{ __('Content') }}</button>
                            <button type="button" wire:click="$set('content_tab', 'preview')" class="{{ $tabBtn }} {{ $content_tab === 'preview' ? $tabActive : $tabIdle }}">{{ __('Effective preview') }}</button>
                            <button type="button" wire:click="$set('content_tab', 'compare')" class="{{ $tabBtn }} {{ $content_tab === 'compare' ? $tabActive : $tabIdle }}">{{ __('Compare') }}</button>
                        </div>
                        @if ($canEdit && $supportsLayers)
                            <div class="flex items-center gap-2">
                                <span class="text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Mode') }}</span>
                                <div class="inline-flex rounded-lg border border-brand-ink/10 bg-white/90 p-0.5">
                                    <button type="button" wire:click="$set('mode', 'layered')" class="rounded-md px-2.5 py-1 text-[11px] font-semibold {{ $mode === 'layered' ? 'bg-brand-sand/80 text-brand-ink shadow-sm' : 'text-brand-moss hover:text-brand-ink' }}">{{ __('Layered') }}</button>
                                    <button type="button" wire:click="$set('mode', 'full_override')" class="rounded-md px-2.5 py-1 text-[11px] font-semibold {{ $mode === 'full_override' ? 'bg-brand-sand/80 text-brand-ink shadow-sm' : 'text-brand-moss hover:text-brand-ink' }}">{{ __('Full file') }}</button>
                                </div>
                            </div>
                        @endif
                    </div>
                    @if ($canEdit && $content_tab === 'edit' && $supportsLayers)
                        <span class="text-[11px] text-brand-moss md:hidden">{{ __('Use the pipeline to pick a layer.') }}</span>
                        <span class="text-[11px] text-brand-moss hidden md:inline">{{ __('Choose a layer in the pipeline →') }}</span>
                    @endif
                </div>

                <div class="px-4 py-3 border-b border-brand-ink/10 bg-white">
                    <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Editing path') }}</p>
                    <p class="mt-1 font-mono text-xs text-brand-ink break-all" title="{{ $editingPath }}">{{ $editingPath }}</p>
                    @if ($isNginxLayeredUi && $active_layer === 'main' && $content_tab === 'edit')
                        <p class="mt-2 text-xs text-brand-moss">{{ __('The main snippet is merged into this virtual host before the “after” includes.') }}</p>
                    @endif
                </div>

                <div class="p-4">
                    @if ($content_tab === 'edit')
                        @if (! $canEdit)
                            <p class="text-sm text-brand-moss mb-4">{{ __('You can preview configuration. Ask an admin for site update access to edit.') }}</p>
                        @else
                            <div class="mb-3 flex items-center justify-between gap-2">
                                <p class="hidden text-[11px] text-brand-moss sm:block">
                                    <kbd class="rounded border border-brand-ink/15 bg-white px-1 font-mono text-[10px]">⌘/Ctrl+S</kbd> {{ __('save draft') }}
                                    <span class="text-brand-mist">·</span>
                                    <kbd class="rounded border border-brand-ink/15 bg-white px-1 font-mono text-[10px]">⌘/Ctrl+Z</kbd> {{ __('undo') }}
                                    <span class="text-brand-mist">·</span>
                                    <kbd class="rounded border border-brand-ink/15 bg-white px-1 font-mono text-[10px]">⇧+Z</kbd> {{ __('redo') }}
                                </p>
                                <button type="button" x-on:click="$dispatch('open-modal', 'webserver-snippet-modal')" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                    <x-heroicon-o-puzzle-piece class="h-4 w-4" aria-hidden="true" />
                                    {{ __('Insert snippet') }}
                                </button>
                            </div>
                        @endif

                        <div wire:key="editor-{{ $mode }}-{{ $active_layer }}">
                            @if ($mode === 'full_override')
                                <label class="block text-xs font-semibold text-brand-moss mb-2">{{ __('Content') }}</label>
                                @if ($canEdit)
                                    <textarea
                                        x-ref="cfgEditor"
                                        wire:model="full_override_body"
                                        rows="22"
                                        class="w-full rounded-lg border border-brand-ink/15 font-mono text-xs leading-relaxed text-brand-ink bg-white shadow-inner focus:border-brand-forest focus:ring-brand-forest min-h-[28rem]"
                                    ></textarea>
                                @else
                                    <textarea
                                        rows="22"
                                        readonly
                                        class="w-full rounded-lg border border-brand-ink/15 font-mono text-xs leading-relaxed text-brand-ink bg-brand-sand/20 min-h-[28rem]"
                                    >{{ $full_override_body }}</textarea>
                                @endif
                            @elseif ($active_layer === 'before')
                                <label class="block text-xs font-semibold text-brand-moss mb-2">{{ __('Content') }}</label>
                                <textarea x-ref="cfgEditor" wire:model.live="before_body" rows="22" @if (! $canEdit) readonly @endif class="w-full rounded-lg border border-brand-ink/15 font-mono text-xs leading-relaxed text-brand-ink bg-white shadow-inner focus:border-brand-forest focus:ring-brand-forest min-h-[28rem] @if (! $canEdit) bg-brand-sand/20 @endif"></textarea>
                            @elseif ($active_layer === 'after')
                                <label class="block text-xs font-semibold text-brand-moss mb-2">{{ __('Content') }}</label>
                                <textarea x-ref="cfgEditor" wire:model.live="after_body" rows="22" @if (! $canEdit) readonly @endif class="w-full rounded-lg border border-brand-ink/15 font-mono text-xs leading-relaxed text-brand-ink bg-white shadow-inner focus:border-brand-forest focus:ring-brand-forest min-h-[28rem] @if (! $canEdit) bg-brand-sand/20 @endif"></textarea>
                            @else
                                {{-- Server / main layer: show the ENTIRE generated vhost (read-only)
                                     so the user sees the whole file, then the editable main snippet
                                     that dply merges into it. The full file updates live as the
                                     snippet changes (render() recomputes the effective preview). --}}
                                <div class="flex items-center justify-between gap-2 mb-2">
                                    <label class="block text-xs font-semibold text-brand-moss">{{ __('Full virtual host file') }}</label>
                                    <div class="flex items-center gap-2">
                                        <span class="inline-flex items-center gap-1 text-[11px] text-brand-moss"><x-heroicon-o-lock-closed class="h-3 w-3" aria-hidden="true" />{{ __('Generated by dply · read-only') }}</span>
                                        @if ($canEdit)
                                            <button type="button" wire:click="$set('mode', 'full_override')" class="inline-flex items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-[11px] font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40" title="{{ __('Take over the whole file — dply stops managing the generated sections for this site.') }}">
                                                <x-heroicon-o-pencil-square class="h-3 w-3" aria-hidden="true" />
                                                {{ __('Edit full file') }}
                                            </button>
                                        @endif
                                    </div>
                                </div>
                                <textarea readonly rows="18" id="server-full-file" class="w-full rounded-lg border border-brand-ink/10 font-mono text-xs leading-relaxed text-brand-ink bg-brand-sand/20 min-h-[20rem]">{{ $effective_config_preview }}</textarea>
                                <p class="mt-1.5 text-[11px] text-brand-moss leading-relaxed">{{ __('This is the complete vhost dply writes to :path. The managed sections (SSL, server_name, roots) are generated from your site settings, so they’re locked here — add your own directives in the main snippet below, or choose “Edit full file” to take over the entire vhost.', ['path' => $config_paths['main_vhost']]) }}</p>

                                <label class="block text-xs font-semibold text-brand-moss mt-5 mb-2">{{ __('Main snippet — merged into the file above') }}</label>
                                <textarea x-ref="cfgEditor" wire:model.live="main_snippet_body" rows="10" @if (! $canEdit) readonly @endif class="w-full rounded-lg border border-brand-ink/15 font-mono text-xs leading-relaxed text-brand-ink bg-white shadow-inner focus:border-brand-forest focus:ring-brand-forest min-h-[14rem] @if (! $canEdit) bg-brand-sand/20 @endif"></textarea>
                            @endif
                        </div>
                    @elseif ($content_tab === 'preview')
                        <label class="block text-xs font-semibold text-brand-moss mb-2">{{ __('Effective configuration (pending apply)') }}</label>
                        <textarea readonly rows="22" id="pending-effective" class="w-full rounded-lg border border-brand-ink/10 bg-brand-sand/30 font-mono text-xs leading-relaxed text-brand-ink min-h-[28rem]">{{ $effective_config_preview }}</textarea>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <x-secondary-button size="sm" type="button" wire:click="downloadEffective">{{ __('Download') }}</x-secondary-button>
                            <x-secondary-button size="sm" type="button" x-data x-on:click="navigator.clipboard.writeText(document.getElementById('pending-effective').value)">{{ __('Copy') }}</x-secondary-button>
                        </div>
                    @else
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <p class="text-xs font-semibold text-brand-moss mb-2">{{ __('Live on server') }}</p>
                                <textarea readonly rows="14" class="w-full rounded-lg border border-brand-ink/10 bg-white font-mono text-[11px] leading-relaxed text-brand-ink min-h-[18rem]">{{ $remote_live_config ?? __('Not loaded — use Fetch.') }}</textarea>
                                <x-secondary-button size="sm" type="button" wire:click="fetchRemoteConfig" wire:loading.attr="disabled" class="mt-2">{{ __('Fetch from server') }}</x-secondary-button>
                                @error('remote_fetch')
                                    <p class="mt-1 text-xs text-red-700">{{ $message }}</p>
                                @enderror
                            </div>
                            <div>
                                <p class="text-xs font-semibold text-brand-moss mb-2">{{ __('Pending apply') }}</p>
                                <textarea readonly rows="14" class="w-full rounded-lg border border-brand-ink/10 bg-brand-sand/30 font-mono text-[11px] leading-relaxed text-brand-ink min-h-[18rem]">{{ $effective_config_preview }}</textarea>
                            </div>
                        </div>
                    @endif
                </div>

                <div class="px-6 py-4 border-t border-brand-ink/10 bg-brand-sand/25 text-xs text-brand-moss leading-relaxed sm:px-7">
                    {{ __('Edits are not live until you apply. Prefer validating on the server before applying.') }}
                </div>
            </div>

            <div class="dply-card overflow-hidden">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <x-icon-badge>
                        <x-heroicon-o-check-badge class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Release') }}</p>
                        <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Check & publish') }}</h2>
                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Validate the pending config, then save or roll out to the server.') }}</p>
                    </div>
                </div>

                <div class="px-5 py-5 space-y-5">
                    {{-- Worker console: live output from the queued apply job. Polls
                         while a run is in flight; the focus event scrolls it into
                         view the moment Apply is pressed. --}}
                    @if ($watchedConsoleRunId)
                        <div wire:poll.3s="resolveWatchedConsoleAction" class="hidden" aria-hidden="true"></div>
                    @endif
                    @if ($webserverConsoleRun)
                        <div
                            id="site-console-action-banner"
                            x-data="{}"
                            x-on:dply-console-action-focus.window="$nextTick(() => { const el = document.getElementById('site-console-action-banner'); if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' }); })"
                        >
                            @include('livewire.partials.console-action-banner-static', [
                                'run' => $webserverConsoleRun,
                                'kindLabels' => (array) config('console_actions.kinds', []),
                            ])
                        </div>
                    @endif

                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-brand-moss mb-3">{{ __('Validate') }}</p>
                        <div class="flex flex-wrap items-center gap-3">
                            <button type="button" wire:click="validate" wire:loading.attr="disabled" wire:target="validate" class="{{ $actionSecondary }} justify-center min-h-[2.75rem]">
                                <x-heroicon-o-check-circle class="h-4 w-4" aria-hidden="true" wire:loading.remove wire:target="validate" />
                                <svg wire:loading wire:target="validate" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.4 0 0 5.4 0 12h4z"></path></svg>
                                <span wire:loading.remove wire:target="validate">{{ __('Validate') }}</span>
                                <span wire:loading wire:target="validate">{{ __('Validating…') }}</span>
                            </button>
                            @if ($config_validated)
                                <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-emerald-700">
                                    <x-heroicon-o-check-circle class="h-4 w-4" aria-hidden="true" />
                                    {{ $validation_source === 'local' ? __('Syntax valid (server unreachable — checked locally)') : __('Valid on server') }}
                                </span>
                            @endif
                        </div>
                        <p class="mt-2 text-[11px] text-brand-moss">{{ __('Runs the real config test on the server. If the server can’t be reached, dply falls back to a local syntax check where available.') }}</p>
                        @if ($validation_message)
                            <pre class="mt-3 text-xs whitespace-pre-wrap text-brand-ink bg-brand-sand/30 rounded-lg p-3 border border-brand-ink/10 max-h-48 overflow-auto">{{ $validation_message }}</pre>
                        @endif
                        @error('validate')
                            <p class="mt-2 text-xs text-red-700">{{ $message }}</p>
                        @enderror
                    </div>

                    @if ($canEdit)
                        <div class="border-t border-brand-ink/10 pt-5">
                            <div class="flex items-center justify-between gap-3 mb-3">
                                <p class="text-[11px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Save & apply') }}</p>
                                {{-- Live state: is the editor in sync with the server, or are there pending edits? --}}
                                @if (! $last_applied_at)
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-sand/60 px-2.5 py-1 text-[11px] font-semibold text-brand-moss">
                                        <span class="h-1.5 w-1.5 rounded-full bg-brand-mist"></span>{{ __('Not applied yet') }}
                                    </span>
                                @elseif ($has_unapplied_changes)
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-100 px-2.5 py-1 text-[11px] font-semibold text-amber-800">
                                        <span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>{{ __('Unapplied changes') }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-2.5 py-1 text-[11px] font-semibold text-emerald-800">
                                        <x-heroicon-o-check class="h-3 w-3" aria-hidden="true" />{{ __('In sync with server') }}
                                    </span>
                                @endif
                            </div>

                            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between lg:gap-6">
                                <div class="flex flex-col sm:flex-row sm:flex-wrap gap-2">
                                    <button type="button" x-on:click="$dispatch('open-modal', 'webserver-history-modal')" class="{{ $actionSecondary }}">
                                        <x-heroicon-o-clock class="h-4 w-4" aria-hidden="true" />
                                        {{ __('History') }}
                                    </button>
                                    <button type="button" wire:click="saveDraft" wire:loading.attr="disabled" wire:target="saveDraft" class="{{ $actionSecondary }}" title="{{ __('Save your edits as the working copy without applying to the server.') }}">
                                        <x-heroicon-o-document-text class="h-4 w-4" aria-hidden="true" />
                                        {{ __('Save draft') }}
                                    </button>
                                    <button type="button" wire:click="saveRevision" wire:loading.attr="disabled" wire:target="saveRevision" class="{{ $actionSecondary }}" title="{{ __('Save a checkpoint you can restore later from History.') }}">
                                        <x-heroicon-o-bookmark-square class="h-4 w-4" aria-hidden="true" />
                                        {{ __('Save revision') }}
                                    </button>
                                    @if ($has_revisions)
                                        <button type="button" wire:click="discardDraft" wire:confirm="{{ __('Discard your unsaved edits and reload the last saved configuration?') }}" wire:loading.attr="disabled" wire:target="discardDraft" class="{{ $actionSecondary }} text-brand-moss" title="{{ __('Throw away unsaved edits and reload the last saved/applied config.') }}">
                                            <x-heroicon-o-arrow-uturn-left class="h-4 w-4" aria-hidden="true" />
                                            {{ __('Discard changes') }}
                                        </button>
                                    @endif
                                </div>
                                <div class="flex flex-col items-stretch gap-1 shrink-0">
                                    <button type="button" wire:click="apply" wire:loading.attr="disabled" @disabled(! $config_validated) class="{{ $actionPrimary }} lg:min-w-[11rem]" title="{{ $config_validated ? __('Write this config to the server and reload.') : __('Validate the configuration first — Apply unlocks once it passes.') }}">
                                        <x-heroicon-o-rocket-launch class="h-4 w-4" aria-hidden="true" wire:loading.remove wire:target="apply" />
                                        <span wire:loading.remove wire:target="apply">{{ __('Apply to server') }}</span>
                                        <span wire:loading wire:target="apply">{{ __('Applying…') }}</span>
                                    </button>
                                    @unless ($config_validated)
                                        <span class="inline-flex items-center justify-center gap-1 text-[11px] text-brand-moss">
                                            <x-heroicon-o-lock-closed class="h-3 w-3" aria-hidden="true" />{{ __('Validate to enable') }}
                                        </span>
                                    @endunless
                                </div>
                            </div>

                            {{-- Timestamps + plain-language explanation of the three save verbs. --}}
                            <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px] text-brand-moss">
                                @if ($draft_saved_at)
                                    <span class="inline-flex items-center gap-1"><x-heroicon-o-document-text class="h-3 w-3" aria-hidden="true" />{{ __('Draft saved :time', ['time' => $draft_saved_at->diffForHumans()]) }}</span>
                                @endif
                                @if ($last_applied_at)
                                    <span class="inline-flex items-center gap-1"><x-heroicon-o-rocket-launch class="h-3 w-3" aria-hidden="true" />{{ __('Applied :time', ['time' => $last_applied_at->diffForHumans()]) }}</span>
                                @endif
                            </div>
                            <p class="mt-2 text-xs leading-relaxed text-brand-moss">
                                {{ __('Save draft keeps a single working copy (overwritten each save) so your edits survive a reload. Save revision adds a restorable checkpoint to History. Apply to server writes the config live — it stays locked until the config passes Validate, and a successful apply also records a revision.') }}
                            </p>

                            @error('apply')
                                <p class="mt-3 text-sm text-red-700">{{ $message }}</p>
                            @enderror
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <aside class="min-w-0 w-full space-y-0 md:col-span-1 md:sticky md:top-6" aria-label="{{ __('Configuration pipeline') }}">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist mb-4">{{ __('Config pipeline') }}</p>

            @php
                $flowActive = 'ring-2 ring-brand-forest border-brand-forest/40 bg-brand-sand/50';
                $flowCard = 'rounded-xl border border-brand-ink/10 bg-white p-3 shadow-sm transition-colors';
            @endphp

            {{-- Step: request --}}
            <div class="flex gap-3">
                <div class="flex flex-col items-center w-9 shrink-0">
                    <span class="flex h-9 w-9 items-center justify-center rounded-full border border-brand-ink/10 bg-brand-sand/50 text-brand-ink">
                        <x-heroicon-o-user class="h-4 w-4" aria-hidden="true" />
                    </span>
                    <span class="w-px flex-1 min-h-[12px] bg-brand-ink/15" aria-hidden="true"></span>
                </div>
                <div class="pb-5 min-w-0 flex-1">
                    <p class="text-sm font-semibold text-brand-ink">{{ __('Visitor request') }}</p>
                    <p class="mt-1 text-xs text-brand-moss leading-relaxed">{{ __('Traffic hits your vhost. Optional caching may apply before config snippets.') }}</p>
                </div>
            </div>

            @if ($showNginxLayerPipeline)
                {{-- Before --}}
                <div class="flex gap-3">
                    <div class="flex flex-col items-center w-9 shrink-0">
                        <span class="w-px flex-1 min-h-[8px] bg-brand-ink/15" aria-hidden="true"></span>
                        <span class="flex h-9 w-9 items-center justify-center rounded-full border border-brand-ink/10 bg-white text-brand-ink">
                            <x-heroicon-o-arrow-up-tray class="h-4 w-4" aria-hidden="true" />
                        </span>
                        <span class="w-px flex-1 min-h-[12px] bg-brand-ink/15" aria-hidden="true"></span>
                    </div>
                    <div class="pb-5 min-w-0 flex-1">
                        <p class="text-sm font-semibold text-brand-ink">{{ __('Before') }}</p>
                        <p class="text-xs text-brand-moss mb-2">{{ __('Included first inside the server block.') }}</p>
                        <button type="button" wire:click="$set('active_layer', 'before'); $set('content_tab', 'edit')" class="w-full text-left {{ $flowCard }} {{ $active_layer === 'before' && $content_tab === 'edit' ? $flowActive : 'hover:border-brand-ink/25' }}">
                            <span class="font-mono text-[11px] text-brand-ink break-all">before/10-dply-layer.conf</span>
                            @if ($active_layer === 'before' && $content_tab === 'edit')
                                <span class="mt-1 inline-flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wide text-brand-forest">{{ __('Editing') }}</span>
                            @endif
                        </button>
                    </div>
                </div>
            @endif

            {{-- Server / core --}}
            <div class="flex gap-3">
                <div class="flex flex-col items-center w-9 shrink-0">
                    <span class="w-px flex-1 min-h-[8px] bg-brand-ink/15" aria-hidden="true"></span>
                    <span class="flex h-9 w-9 items-center justify-center rounded-full border border-brand-ink/10 bg-white text-brand-ink">
                        <x-heroicon-o-server-stack class="h-4 w-4" aria-hidden="true" />
                    </span>
                    <span class="w-px flex-1 min-h-[12px] bg-brand-ink/15" aria-hidden="true"></span>
                </div>
                <div class="pb-5 min-w-0 flex-1 space-y-2">
                    <p class="text-sm font-semibold text-brand-ink">{{ __('Server') }}</p>
                    @if ($showNginxLayerPipeline)
                        <button type="button" wire:click="$set('active_layer', 'main'); $set('content_tab', 'edit')" class="w-full text-left {{ $flowCard }} {{ $active_layer === 'main' && $content_tab === 'edit' ? $flowActive : 'hover:border-brand-ink/25' }}">
                            <span class="font-mono text-[11px] text-brand-ink break-all block">{{ $site->webserverConfigBasename() }}.conf</span>
                            <span class="text-xs text-brand-moss mt-1 block">{{ __('Primary vhost · edit the main snippet merged into this file') }}</span>
                            @if ($active_layer === 'main' && $content_tab === 'edit')
                                <span class="mt-2 inline-flex text-[10px] font-semibold uppercase tracking-wide text-brand-forest">{{ __('Editing') }}</span>
                            @endif
                        </button>
                    @else
                        <button type="button" wire:click="$set('active_layer', '{{ $sidebarServerTargetLayer }}'); $set('content_tab', 'edit')" class="w-full text-left {{ $flowCard }} {{ (($mode === 'full_override' && $active_layer === 'full') || ($mode !== 'full_override' && $active_layer === 'main')) && $content_tab === 'edit' ? $flowActive : 'hover:border-brand-ink/25' }}">
                            <span class="font-mono text-[11px] text-brand-ink break-all block">{{ $config_paths['main_vhost'] }}</span>
                            <span class="text-xs text-brand-moss mt-1 block">{{ $mode === 'full_override' ? __('Full configuration file') : __('Layered snippets (preview uses effective config)') }}</span>
                            @if ((($mode === 'full_override' && $active_layer === 'full') || ($mode !== 'full_override' && $active_layer === 'main')) && $content_tab === 'edit')
                                <span class="mt-2 inline-flex text-[10px] font-semibold uppercase tracking-wide text-brand-forest">{{ __('Editing') }}</span>
                            @endif
                        </button>
                        @if (! $showNginxLayerPipeline && $mode === \App\Models\SiteWebserverConfigProfile::MODE_LAYERED)
                            <div class="flex flex-wrap gap-1.5 pt-1">
                                <button type="button" wire:click="$set('active_layer', 'before'); $set('content_tab', 'edit')" class="text-[11px] font-medium rounded-md px-2 py-1 border border-brand-ink/15 {{ $active_layer === 'before' ? 'bg-brand-sand/80 text-brand-ink' : 'text-brand-moss hover:bg-brand-sand/40' }}">{{ __('Before layer') }}</button>
                                <button type="button" wire:click="$set('active_layer', 'main'); $set('content_tab', 'edit')" class="text-[11px] font-medium rounded-md px-2 py-1 border border-brand-ink/15 {{ $active_layer === 'main' ? 'bg-brand-sand/80 text-brand-ink' : 'text-brand-moss hover:bg-brand-sand/40' }}">{{ __('Main') }}</button>
                                <button type="button" wire:click="$set('active_layer', 'after'); $set('content_tab', 'edit')" class="text-[11px] font-medium rounded-md px-2 py-1 border border-brand-ink/15 {{ $active_layer === 'after' ? 'bg-brand-sand/80 text-brand-ink' : 'text-brand-moss hover:bg-brand-sand/40' }}">{{ __('After layer') }}</button>
                            </div>
                        @endif
                    @endif
                </div>
            </div>

            @if ($showNginxLayerPipeline)
                <div class="flex gap-3">
                    <div class="flex flex-col items-center w-9 shrink-0">
                        <span class="w-px flex-1 min-h-[8px] bg-brand-ink/15" aria-hidden="true"></span>
                        <span class="flex h-9 w-9 items-center justify-center rounded-full border border-brand-ink/10 bg-white text-brand-ink">
                            <x-heroicon-o-arrow-down-tray class="h-4 w-4" aria-hidden="true" />
                        </span>
                        <span class="w-px flex-1 min-h-[12px] bg-brand-ink/15" aria-hidden="true"></span>
                    </div>
                    <div class="pb-5 min-w-0 flex-1">
                        <p class="text-sm font-semibold text-brand-ink">{{ __('After') }}</p>
                        <p class="text-xs text-brand-moss mb-2">{{ __('Included last inside the server block.') }}</p>
                        <button type="button" wire:click="$set('active_layer', 'after'); $set('content_tab', 'edit')" class="w-full text-left {{ $flowCard }} {{ $active_layer === 'after' && $content_tab === 'edit' ? $flowActive : 'hover:border-brand-ink/25' }}">
                            <span class="font-mono text-[11px] text-brand-ink break-all">after/10-dply-layer.conf</span>
                            @if (trim($after_body) === '')
                                <span class="mt-2 block text-[11px] italic text-brand-moss">{{ __('Placeholder until you add directives') }}</span>
                            @endif
                            @if ($active_layer === 'after' && $content_tab === 'edit')
                                <span class="mt-1 inline-flex text-[10px] font-semibold uppercase tracking-wide text-brand-forest">{{ __('Editing') }}</span>
                            @endif
                        </button>
                    </div>
                </div>
            @endif

            {{-- Result --}}
            <div class="flex gap-3">
                <div class="flex flex-col items-center w-9 shrink-0">
                    <span class="w-px flex-1 min-h-[8px] bg-brand-ink/15" aria-hidden="true"></span>
                    <span class="flex h-9 w-9 items-center justify-center rounded-full border border-brand-ink/10 bg-brand-sand/50 text-brand-ink">
                        <x-heroicon-o-computer-desktop class="h-4 w-4" aria-hidden="true" />
                    </span>
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-brand-ink">{{ __('Response') }}</p>
                    <p class="mt-1 text-xs text-brand-moss leading-relaxed">{{ __('The effective config your visitors receive after all includes and snippets are applied.') }}</p>
                </div>
            </div>

        </aside>
    </div>

    <x-modal name="webserver-history-modal" maxWidth="lg" overlayClass="bg-brand-ink/40">
        <div class="relative border-b border-brand-ink/10 px-6 py-5">
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('History') }}</p>
            <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Revision history') }}</h2>
            <p class="mt-2 pr-10 text-sm leading-6 text-brand-moss">{{ __('Restore any prior saved revision back into the editor.') }}</p>
            <button type="button" x-on:click="$dispatch('close')" class="absolute right-4 top-4 inline-flex h-8 w-8 items-center justify-center rounded-lg text-brand-mist transition-colors hover:bg-brand-sand/40 hover:text-brand-ink focus:outline-none focus:ring-2 focus:ring-brand-sage/40" aria-label="{{ __('Close') }}" title="{{ __('Close') }}">
                <x-heroicon-o-x-mark class="h-5 w-5" />
            </button>
        </div>

        <div class="max-h-[60vh] overflow-y-auto">
            <ul class="divide-y divide-brand-ink/10">
                @forelse ($revisions as $rev)
                    <li class="flex items-start justify-between gap-2 px-6 py-4">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-brand-ink">{{ $rev->summary ?? __('Snapshot') }}</p>
                            <p class="mt-1 text-xs text-brand-moss">{{ $rev->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</p>
                        </div>
                        <x-secondary-button size="sm" type="button" wire:click="restoreRevision('{{ $rev->id }}')" class="shrink-0">{{ __('Restore') }}</x-secondary-button>
                    </li>
                @empty
                    <li class="px-6 py-10 text-center text-sm text-brand-moss">{{ __('No revisions yet.') }}</li>
                @endforelse
            </ul>
        </div>

        <div class="flex items-center justify-end gap-2 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
            <x-secondary-button type="button" x-on:click="$dispatch('close')">{{ __('Close') }}</x-secondary-button>
        </div>
    </x-modal>

    <x-cli-snippet tone="stub" />
        </main>
    </div>

    {{-- Config-snippet picker — inserts an engine-specific block into the editor. --}}
    <x-modal name="webserver-snippet-modal" max-width="2xl" focusable>
        <div class="flex flex-col" style="max-height: 80vh;">
            <div class="flex items-start justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5">
                <div class="flex min-w-0 items-start gap-3">
                    <x-icon-badge>
                        <x-heroicon-o-puzzle-piece class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Config snippets') }}</p>
                        <div class="mt-0.5 flex items-center gap-2">
                            <h2 class="text-base font-semibold text-brand-ink">{{ __('Insert a snippet') }}</h2>
                            <span class="inline-flex items-center rounded-full bg-brand-ink/5 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ $config_paths['engine_label'] }}</span>
                        </div>
                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Drops a ready-made block into the config you’re editing. Review and adjust values (ports, paths) before applying.') }}</p>
                    </div>
                </div>
                <button type="button" x-on:click="$dispatch('close-modal', 'webserver-snippet-modal')" class="shrink-0 rounded-lg p-1 text-brand-mist hover:bg-brand-sand/40">
                    <x-heroicon-o-x-mark class="h-5 w-5" />
                </button>
            </div>

            <div class="border-b border-brand-ink/10 px-6 py-3">
                <div class="relative">
                    <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-brand-mist" aria-hidden="true" />
                    <input type="search" wire:model.live.debounce.250ms="snippetSearch" placeholder="{{ __('Filter snippets…') }}" class="w-full rounded-lg border border-brand-ink/15 bg-white py-2 pl-9 pr-3 text-sm shadow-sm focus:border-brand-ink focus:ring-1 focus:ring-brand-ink" />
                </div>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto px-6 py-4">
                {{-- Placeholder tokens: inserted literally ({{TOKEN}}); the
                     resolved value is shown only as a hint to fill in later. --}}
                @if ($snippetSearch === '')
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Placeholders') }}</p>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach ($configPlaceholders as $ph)
                            <button
                                type="button"
                                x-on:click="$dispatch('insert-snippet-text', { text: @js($ph['token']), block: false })"
                                title="{{ $ph['label'] }}{{ $ph['example'] !== '' ? ' — '.$ph['example'] : '' }}"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 font-mono text-[11px] text-brand-ink shadow-sm hover:bg-brand-sand/40"
                            >
                                <x-heroicon-o-plus class="h-3 w-3 text-brand-mist" aria-hidden="true" />
                                {{ $ph['token'] }}
                            </button>
                        @endforeach
                    </div>
                    <p class="mt-2 text-[11px] text-brand-moss">{{ __('Inserts the token at your cursor — replace it before applying. Hover for this site’s value.') }}</p>
                    <hr class="my-4 border-brand-ink/10" />
                @endif

                @if ($webserverSnippets->isEmpty())
                    <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/20 px-4 py-10 text-center text-sm text-brand-moss">
                        {{ __('No snippets match.') }}
                    </div>
                @else
                    <ul class="divide-y divide-brand-ink/10">
                        @foreach ($webserverSnippets as $snippet)
                            <li class="flex flex-wrap items-center justify-between gap-3 py-3" wire:key="snippet-{{ $snippet['key'] }}">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold text-brand-ink">{{ $snippet['name'] }}</p>
                                    @if ($snippet['description'] !== '')
                                        <p class="mt-0.5 text-xs text-brand-moss">{{ $snippet['description'] }}</p>
                                    @endif
                                </div>
                                <button
                                    type="button"
                                    x-on:click="$dispatch('insert-snippet-text', { text: @js($snippet['content']), block: true }); $dispatch('close-modal', 'webserver-snippet-modal')"
                                    class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                                >
                                    <x-heroicon-o-arrow-down-tray class="h-4 w-4" aria-hidden="true" />
                                    {{ __('Insert at cursor') }}
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="flex items-center justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-3">
                <button type="button" x-on:click="$dispatch('close-modal', 'webserver-snippet-modal')" class="rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Done') }}</button>
            </div>
        </div>
    </x-modal>
</div>
