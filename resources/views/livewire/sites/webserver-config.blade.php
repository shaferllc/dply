@php
    $btnPrimary = 'inline-flex items-center justify-center gap-2 rounded-lg bg-brand-ink px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-brand-cream shadow-sm hover:bg-brand-forest transition-colors disabled:cursor-not-allowed disabled:opacity-50';
    $btnSecondary = 'inline-flex items-center justify-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-brand-ink shadow-sm hover:bg-brand-sand/50 transition-colors';
    $actionSecondary = 'inline-flex items-center justify-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/50 transition-colors';
    $actionPrimary = 'inline-flex items-center justify-center gap-2 rounded-lg bg-brand-ink px-5 py-2.5 text-sm font-semibold text-brand-cream shadow-sm hover:bg-brand-forest transition-colors disabled:cursor-not-allowed disabled:opacity-50 w-full sm:w-auto';
    $canEdit = auth()->user()->can('update', $site);
    $isNginxLayeredUi = $site->webserver() === 'nginx' && $mode === \App\Models\SiteWebserverConfigProfile::MODE_LAYERED;
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
    <nav class="mb-6 text-sm text-brand-moss" aria-label="{{ __('Breadcrumb') }}">
        <ol class="flex flex-wrap items-center gap-2">
            <li><a href="{{ route('dashboard') }}" wire:navigate class="transition-colors hover:text-brand-ink">{{ __('Dashboard') }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li><a href="{{ route('servers.index') }}" wire:navigate class="transition-colors hover:text-brand-ink">{{ __('Servers') }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li><a href="{{ route('servers.sites', $server) }}" wire:navigate class="transition-colors hover:text-brand-ink truncate max-w-[10rem]" title="{{ $server->name }}">{{ $server->name }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li><a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'general']) }}" wire:navigate class="transition-colors hover:text-brand-ink truncate max-w-[10rem]" title="{{ $site->name }}">{{ $site->name }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li class="font-medium text-brand-ink">{{ __('Web server config') }}</li>
        </ol>
    </nav>

    <div class="space-y-6 lg:grid lg:grid-cols-12 lg:gap-10 lg:space-y-0">
        @include('livewire.sites.settings.partials.sidebar')

        <main class="min-w-0 space-y-6 lg:col-span-9">
    <x-page-header
        :eyebrow="__('Web server config')"
        :title="$config_paths['engine_label']"
        :description="__('Managed configuration for :site', ['site' => $site->name])"
        doc-route="docs.index"
        flush
        compact
    />

    @if ($core_changed_warning)
        <div class="mb-6 dply-card overflow-hidden">
            <div class="flex items-start gap-3 bg-amber-50/60 px-6 py-5 sm:px-7">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-amber-100 text-amber-700 ring-1 ring-amber-200">
                    <x-heroicon-o-exclamation-triangle class="h-5 w-5" aria-hidden="true" />
                </span>
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
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-information-circle class="h-5 w-5" aria-hidden="true" />
                </span>
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
            <div class="dply-card overflow-hidden min-w-0">
                <div class="flex flex-col gap-3 border-b border-brand-ink/10 bg-brand-cream/40 px-4 py-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
                    <div class="flex flex-wrap items-center gap-2 sm:gap-3">
                        <div class="flex flex-wrap gap-1 rounded-lg bg-white/80 p-0.5 border border-brand-ink/10">
                            <button type="button" wire:click="$set('content_tab', 'edit')" class="{{ $tabBtn }} {{ $content_tab === 'edit' ? $tabActive : $tabIdle }}">{{ __('Content') }}</button>
                            <button type="button" wire:click="$set('content_tab', 'preview')" class="{{ $tabBtn }} {{ $content_tab === 'preview' ? $tabActive : $tabIdle }}">{{ __('Effective preview') }}</button>
                            <button type="button" wire:click="$set('content_tab', 'compare')" class="{{ $tabBtn }} {{ $content_tab === 'compare' ? $tabActive : $tabIdle }}">{{ __('Compare') }}</button>
                        </div>
                        @if ($canEdit)
                            <div class="flex items-center gap-2">
                                <span class="text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Mode') }}</span>
                                <div class="inline-flex rounded-lg border border-brand-ink/10 bg-white/90 p-0.5">
                                    <button type="button" wire:click="$set('mode', 'layered')" class="rounded-md px-2.5 py-1 text-[11px] font-semibold {{ $mode === 'layered' ? 'bg-brand-sand/80 text-brand-ink shadow-sm' : 'text-brand-moss hover:text-brand-ink' }}">{{ __('Layered') }}</button>
                                    <button type="button" wire:click="$set('mode', 'full_override')" class="rounded-md px-2.5 py-1 text-[11px] font-semibold {{ $mode === 'full_override' ? 'bg-brand-sand/80 text-brand-ink shadow-sm' : 'text-brand-moss hover:text-brand-ink' }}">{{ __('Full file') }}</button>
                                </div>
                            </div>
                        @endif
                    </div>
                    @if ($canEdit && $content_tab === 'edit')
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
                        @endif

                        <div wire:key="editor-{{ $mode }}-{{ $active_layer }}">
                            @if ($mode === 'full_override')
                                <label class="block text-xs font-semibold text-brand-moss mb-2">{{ __('Content') }}</label>
                                @if ($canEdit)
                                    <textarea
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
                            @else
                                <label class="block text-xs font-semibold text-brand-moss mb-2">{{ __('Content') }}</label>
                                @if ($active_layer === 'before')
                                    <textarea wire:model.live="before_body" rows="22" @if (! $canEdit) readonly @endif class="w-full rounded-lg border border-brand-ink/15 font-mono text-xs leading-relaxed text-brand-ink bg-white shadow-inner focus:border-brand-forest focus:ring-brand-forest min-h-[28rem] @if (! $canEdit) bg-brand-sand/20 @endif"></textarea>
                                @elseif ($active_layer === 'after')
                                    <textarea wire:model.live="after_body" rows="22" @if (! $canEdit) readonly @endif class="w-full rounded-lg border border-brand-ink/15 font-mono text-xs leading-relaxed text-brand-ink bg-white shadow-inner focus:border-brand-forest focus:ring-brand-forest min-h-[28rem] @if (! $canEdit) bg-brand-sand/20 @endif"></textarea>
                                @else
                                    <textarea wire:model.live="main_snippet_body" rows="22" @if (! $canEdit) readonly @endif class="w-full rounded-lg border border-brand-ink/15 font-mono text-xs leading-relaxed text-brand-ink bg-white shadow-inner focus:border-brand-forest focus:ring-brand-forest min-h-[28rem] @if (! $canEdit) bg-brand-sand/20 @endif"></textarea>
                                @endif
                            @endif
                        </div>
                    @elseif ($content_tab === 'preview')
                        <label class="block text-xs font-semibold text-brand-moss mb-2">{{ __('Effective configuration (pending apply)') }}</label>
                        <textarea readonly rows="22" id="pending-effective" class="w-full rounded-lg border border-brand-ink/10 bg-brand-sand/30 font-mono text-xs leading-relaxed text-brand-ink min-h-[28rem]">{{ $effective_config_preview }}</textarea>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <button type="button" wire:click="downloadEffective" class="{{ $btnSecondary }}">{{ __('Download') }}</button>
                            <button type="button" x-data @click="navigator.clipboard.writeText(document.getElementById('pending-effective').value)" class="{{ $btnSecondary }}">{{ __('Copy') }}</button>
                        </div>
                    @else
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <p class="text-xs font-semibold text-brand-moss mb-2">{{ __('Live on server') }}</p>
                                <textarea readonly rows="14" class="w-full rounded-lg border border-brand-ink/10 bg-white font-mono text-[11px] leading-relaxed text-brand-ink min-h-[18rem]">{{ $remote_live_config ?? __('Not loaded — use Fetch.') }}</textarea>
                                <button type="button" wire:click="fetchRemoteConfig" wire:loading.attr="disabled" class="mt-2 {{ $btnSecondary }}">{{ __('Fetch from server') }}</button>
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
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                        <x-heroicon-o-check-badge class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Release') }}</p>
                        <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Check & publish') }}</h2>
                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Validate the pending config, then save or roll out to the server.') }}</p>
                    </div>
                </div>

                <div class="px-5 py-5 space-y-5">
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-brand-moss mb-3">{{ __('Validate') }}</p>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 max-w-xl">
                            <button type="button" wire:click="validateLocalAction" class="{{ $actionSecondary }} justify-center min-h-[2.75rem]">{{ __('Validate locally') }}</button>
                            <button type="button" wire:click="validateRemoteAction" class="{{ $actionSecondary }} justify-center min-h-[2.75rem]">{{ __('Validate on server') }}</button>
                        </div>
                        @if ($local_validation_message)
                            <pre class="mt-3 text-xs whitespace-pre-wrap text-brand-ink bg-brand-sand/30 rounded-lg p-3 border border-brand-ink/10 max-h-48 overflow-auto">{{ $local_validation_message }}</pre>
                        @endif
                        @error('local')
                            <p class="mt-2 text-xs text-red-700">{{ $message }}</p>
                        @enderror
                        @if ($remote_validation_message)
                            <pre class="mt-3 text-xs whitespace-pre-wrap text-brand-ink bg-brand-sand/30 rounded-lg p-3 border border-brand-ink/10 max-h-48 overflow-auto">{{ $remote_validation_message }}</pre>
                        @endif
                        @error('remote')
                            <p class="mt-2 text-xs text-red-700">{{ $message }}</p>
                        @enderror
                    </div>

                    @if ($canEdit)
                        <div class="border-t border-brand-ink/10 pt-5">
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-brand-moss mb-3">{{ __('Save & apply') }}</p>
                            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between lg:gap-6">
                                <div class="flex flex-col sm:flex-row sm:flex-wrap gap-2">
                                    <button type="button" wire:click="$set('show_history_modal', true)" class="{{ $actionSecondary }}">{{ __('History') }}</button>
                                    <button type="button" wire:click="saveDraft" class="{{ $actionSecondary }}">{{ __('Save draft') }}</button>
                                    <button type="button" wire:click="saveRevision" class="{{ $actionSecondary }}">{{ __('Save revision') }}</button>
                                </div>
                                <button type="button" wire:click="apply" wire:loading.attr="disabled" class="{{ $actionPrimary }} shrink-0 lg:min-w-[11rem]">
                                    <span wire:loading.remove wire:target="apply">{{ __('Apply to server') }}</span>
                                    <span wire:loading wire:target="apply">{{ __('Applying…') }}</span>
                                </button>
                            </div>
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

            <div class="mt-6 dply-card overflow-hidden">
                <div class="flex items-start gap-3 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                        <x-heroicon-o-book-open class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Reference') }}</p>
                        <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Runbook') }}</h2>
                        <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                            <a href="{{ route('scripts.marketplace') }}" wire:navigate class="font-medium text-brand-forest hover:text-brand-sage hover:underline">{{ __('Script marketplace') }}</a>
                            <span class="text-brand-mist" aria-hidden="true">·</span>
                            <a href="{{ route('servers.run', $server) }}" wire:navigate class="font-medium text-brand-forest hover:text-brand-sage hover:underline">{{ __('Server commands') }}</a>
                        </div>
                    </div>
                </div>
            </div>
        </aside>
    </div>

    <x-modal name="webserver-history-modal" :show="$show_history_modal" maxWidth="lg" overlayClass="bg-brand-ink/40" wire:model.live="show_history_modal">
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
                        <button type="button" wire:click="restoreRevision('{{ $rev->id }}')" class="{{ $btnSecondary }} shrink-0">{{ __('Restore') }}</button>
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
</div>
