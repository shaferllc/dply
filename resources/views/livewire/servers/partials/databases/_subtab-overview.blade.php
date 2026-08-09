@php
    $engineIcon = match ($engine) {
        'sqlite' => 'heroicon-o-archive-box',
        default => 'heroicon-o-circle-stack',
    };
    $engineInFlight = $engineRow && in_array($engineRow->status, [
        \App\Models\ServerDatabaseEngine::STATUS_PENDING,
        \App\Models\ServerDatabaseEngine::STATUS_INSTALLING,
        \App\Models\ServerDatabaseEngine::STATUS_UNINSTALLING,
    ], true);
@endphp

@if ($isManageable)
    <div class="{{ $card }}">
        @php
            $engineHeadNote = $engineRow && filled($engineRow->version)
                ? __('Version :version · port :port', ['version' => $engineRow->version, 'port' => $engineRow->port])
                : $dbEngineInfoForTab['tagline'];
        @endphp
        <x-workspace-panel-head
            dense
            :icon="$engineIcon"
            :title="$dbEngineInfoForTab['label']"
            :note="$engineHeadNote"
            class="border-b border-brand-ink/10"
        >
            @if ($engineRow)
                @php
                    $statusPill = match ($engineRow->status) {
                        \App\Models\ServerDatabaseEngine::STATUS_RUNNING => ['classes' => 'text-emerald-700', 'dot' => 'bg-emerald-500', 'label' => __('Running')],
                        \App\Models\ServerDatabaseEngine::STATUS_STOPPED => ['classes' => 'text-amber-800', 'dot' => 'bg-amber-500', 'label' => __('Stopped')],
                        \App\Models\ServerDatabaseEngine::STATUS_FAILED => ['classes' => 'text-rose-700', 'dot' => 'bg-rose-500', 'label' => __('Failed')],
                        default => ['classes' => 'text-sky-800', 'dot' => 'bg-sky-500', 'label' => __('Working')],
                    };
                @endphp
                <x-slot:actions>
                    <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-white px-2 py-0.5 text-2xs font-semibold ring-1 ring-brand-ink/10 {{ $statusPill['classes'] }}">
                        @if ($engineInFlight)
                            <x-spinner variant="forest" size="sm" />
                        @else
                            <span aria-hidden="true" class="inline-block h-1.5 w-1.5 rounded-full {{ $statusPill['dot'] }}"></span>
                        @endif
                        {{ $statusPill['label'] }}
                    </span>
                </x-slot:actions>
            @endif
        </x-workspace-panel-head>

        <div class="px-4 py-3.5 sm:px-5">
            {{-- Gated "coming soon" engines never reach this panel — the engine
                 dispatcher routes all their tabs (bar Info) to the shared
                 <x-workspace-coming-soon> teaser. This branch handles available
                 engines that simply aren't installed on this box yet. --}}
            @if (! $engineRow)
                <p class="max-w-2xl text-sm leading-relaxed text-brand-moss">
                    {{ $dbEngineInfoForTab['description'] }}
                </p>
                <p class="mt-3 max-w-2xl text-sm text-brand-moss">
                    {{ __('Runs apt + systemctl over SSH. Dply checks memory and disk before install so a small box does not OOM mid-install.') }}
                </p>
                <div class="mt-5 flex flex-wrap items-center gap-3">
                    <button
                        type="button"
                        wire:click="installDatabaseEngine('{{ $engine }}')"
                        wire:loading.attr="disabled"
                        wire:target="installDatabaseEngine"
                        class="inline-flex items-center gap-2 rounded-lg bg-brand-forest px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-brand-forest/90 disabled:opacity-50"
                    >
                        <x-heroicon-o-cloud-arrow-down class="h-4 w-4" />
                        <span wire:loading.remove wire:target="installDatabaseEngine">{{ __('Install :engine', ['engine' => $dbEngineInfoForTab['label']]) }}</span>
                        <span wire:loading wire:target="installDatabaseEngine">{{ __('Queueing…') }}</span>
                    </button>
                    <button
                        type="button"
                        wire:click="setEngineSubtab('info')"
                        class="inline-flex h-7 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                    >
                        <x-heroicon-o-information-circle class="h-4 w-4" aria-hidden="true" />
                        {{ __('Learn more') }}
                    </button>
                </div>
            @elseif ($engineInFlight)
                <p class="max-w-2xl text-sm leading-relaxed text-brand-moss">
                    {{ __(':engine is changing — see the progress banner above for live status and output.', ['engine' => $dbEngineInfoForTab['label']]) }}
                </p>
                <div class="mt-5">
                    <button
                        type="button"
                        wire:click="openConfirmActionModal('stopAndRevertDatabaseEngineInstall', ['{{ $engine }}'], @js(__('Stop and revert :engine install?', ['engine' => $dbEngineInfoForTab['label']])), @js(__('Marks the install as failed and runs apt purge on the server to clean up any partial state. Use this when the install has stalled.')), @js(__('Stop & revert')), true)"
                        class="inline-flex h-7 items-center gap-1 rounded-md border border-rose-300 bg-white px-2 text-xs font-semibold text-rose-800 shadow-sm transition hover:bg-rose-50"
                    >
                        <x-heroicon-o-arrow-uturn-left class="h-3.5 w-3.5 shrink-0" />
                        {{ __('Stop & revert') }}
                    </button>
                </div>
            @else
                {{-- Status, version and port ride the dense head above — repeating
                     them in a three-up definition list was the same three facts twice. --}}
                @if ($engineRow->status === \App\Models\ServerDatabaseEngine::STATUS_FAILED && filled($engineRow->error_message))
                    <p class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs leading-relaxed text-rose-800">
                        {{ $engineRow->error_message }}
                    </p>
                    <div class="mt-4">
                        <button
                            type="button"
                            wire:click="installDatabaseEngine('{{ $engine }}')"
                            wire:loading.attr="disabled"
                            wire:target="installDatabaseEngine"
                            class="inline-flex items-center gap-2 rounded-lg bg-brand-forest px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-brand-forest/90 disabled:opacity-50"
                        >
                            <x-heroicon-o-cloud-arrow-down class="h-4 w-4" />
                            <span wire:loading.remove wire:target="installDatabaseEngine">{{ __('Retry install') }}</span>
                            <span wire:loading wire:target="installDatabaseEngine">{{ __('Queueing…') }}</span>
                        </button>
                    </div>
                @elseif (in_array($engineRow->status, [
                    \App\Models\ServerDatabaseEngine::STATUS_RUNNING,
                    \App\Models\ServerDatabaseEngine::STATUS_STOPPED,
                ], true))
                    <div class="flex flex-wrap gap-2">
                        @if ($engineRow->is_default)
                            <span class="inline-flex h-7 items-center gap-1 rounded-md border border-brand-forest/20 bg-brand-forest/10 px-2 text-xs font-semibold text-brand-forest">
                                <x-heroicon-o-check-badge class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('Primary engine') }}
                            </span>
                        @else
                            <button
                                type="button"
                                wire:click="setPrimaryEngine('{{ $engine }}')"
                                wire:loading.attr="disabled"
                                wire:target="setPrimaryEngine"
                                title="{{ __('Make :engine the default engine for new sites on this server.', ['engine' => $dbEngineInfoForTab['label']]) }}"
                                class="inline-flex h-7 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:opacity-60"
                            >
                                <x-heroicon-o-star class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('Make primary') }}
                            </button>
                        @endif
                        @if ($engineRow->status === \App\Models\ServerDatabaseEngine::STATUS_RUNNING)
                            <button
                                type="button"
                                wire:click="openConfirmActionModal('setDatabaseEngineActivation', ['{{ $engine }}', false], @js(__('Deactivate :engine?', ['engine' => $dbEngineInfoForTab['label']])), @js(__('Stops the engine and disables it from starting at boot. Sites connected to it will lose their database until you activate it again. Data and binaries on the server are left untouched.')), @js(__('Deactivate')), true)"
                                class="inline-flex h-7 items-center gap-1 rounded-md border border-amber-200 bg-amber-50 px-2 text-xs font-semibold text-amber-800 transition hover:bg-amber-100"
                            >
                                <x-heroicon-o-pause-circle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('Deactivate') }}
                            </button>
                        @else
                            <button
                                type="button"
                                wire:click="setDatabaseEngineActivation('{{ $engine }}', true)"
                                wire:loading.attr="disabled"
                                wire:target="setDatabaseEngineActivation('{{ $engine }}', true)"
                                class="inline-flex h-7 items-center gap-1 rounded-md border border-emerald-200 bg-emerald-50 px-2 text-xs font-semibold text-emerald-700 transition hover:bg-emerald-100 disabled:opacity-60"
                            >
                                <x-heroicon-o-play-circle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('Activate') }}
                            </button>
                        @endif
                        <button
                            type="button"
                            wire:click="openConfirmActionModal('uninstallDatabaseEngine', ['{{ $engine }}'], @js(__('Uninstall :engine', ['engine' => $dbEngineInfoForTab['label']])), @js(__('apt purge will remove the engine and its data dirs from the server. Existing tracked databases stay in Dply but won\'t have a live engine to talk to.')), @js(__('Uninstall')), true)"
                            class="inline-flex h-7 items-center gap-1 rounded-md border border-red-200 bg-red-50 px-2 text-xs font-semibold text-red-700 transition hover:bg-red-100"
                        >
                            {{ __('Uninstall') }}
                        </button>
                        @if ($showEngineWorkspace)
                            <button
                                type="button"
                                wire:click="setEngineSubtab('databases')"
                                class="inline-flex h-7 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                            >
                                <x-heroicon-o-circle-stack class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('Manage databases') }}
                            </button>
                        @endif
                    </div>
                @endif
            @endif
        </div>
    </div>

    {{-- Remote access is managed per-database on the Networking tab. --}}
@elseif ($engine === 'sqlite' && ($capabilities['sqlite'] ?? false))
    <div class="{{ $card }}">
        <x-workspace-panel-head
            dense
            :icon="$engineIcon"
            :title="$dbEngineInfoForTab['label']"
            :note="$dbEngineInfoForTab['description']"
            class="border-b border-brand-ink/10"
        >
            <x-slot:actions>
                <span class="inline-flex shrink-0 items-center rounded-full bg-emerald-50 px-2 py-0.5 text-2xs font-semibold text-emerald-700 ring-1 ring-emerald-200">{{ __('Active') }}</span>
                <button
                    type="button"
                    wire:click="setEngineSubtab('databases')"
                    class="inline-flex h-6 items-center gap-1 whitespace-nowrap rounded-md bg-brand-ink px-2 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest"
                >
                    <x-heroicon-m-circle-stack class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    {{ __('Manage databases') }}
                </button>
            </x-slot:actions>
        </x-workspace-panel-head>
    </div>
@else
    {{-- SQLite that the capability probe hasn't found on the box. This branch used
         to be missing entirely, so the Overview subtab rendered a blank card. --}}
    <div class="{{ $card }}">
        <x-workspace-panel-head
            dense
            :icon="$engineIcon"
            :title="$dbEngineInfoForTab['label']"
            :note="$dbEngineInfoForTab['description']"
            class="border-b border-brand-ink/10"
        />
        <div class="px-4 py-5 sm:px-5">
            <x-empty-state
                borderless
                compact
                icon="heroicon-o-archive-box"
                tone="sage"
                :title="__(':engine not detected on this server', ['engine' => $dbEngineInfoForTab['label']])"
                :description="__('The sqlite3 binary was not found. Open Advanced → Server sync and click Recheck engines after installing it.')"
            >
                <x-slot:actions>
                    <button
                        type="button"
                        wire:click="setWorkspaceTab('advanced')"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40"
                    >
                        {{ __('Open Advanced') }}
                    </button>
                </x-slot:actions>
            </x-empty-state>
        </div>
    </div>
@endif
