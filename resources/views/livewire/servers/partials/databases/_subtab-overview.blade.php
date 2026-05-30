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
    <div class="{{ $card }} overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-4 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-8">
            <div class="flex items-center gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-dynamic-component :component="$engineIcon" class="h-5 w-5 text-brand-forest" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Engine') }}</p>
                    <h3 class="text-lg font-semibold text-brand-ink">{{ $dbEngineInfoForTab['label'] }}</h3>
                    @if ($engineRow && filled($engineRow->version))
                        <p class="font-mono text-[11px] text-brand-mist">{{ $engineRow->version }}</p>
                    @elseif (! $engineRow)
                        <p class="mt-0.5 text-[12px] text-brand-moss">{{ $dbEngineInfoForTab['tagline'] }}</p>
                    @endif
                </div>
            </div>
            @if ($engineRow)
                @php
                    $statusPill = match ($engineRow->status) {
                        \App\Models\ServerDatabaseEngine::STATUS_RUNNING => ['classes' => 'text-emerald-700', 'dot' => 'bg-emerald-500', 'label' => __('Running')],
                        \App\Models\ServerDatabaseEngine::STATUS_STOPPED => ['classes' => 'text-amber-800', 'dot' => 'bg-amber-500', 'label' => __('Stopped')],
                        \App\Models\ServerDatabaseEngine::STATUS_FAILED => ['classes' => 'text-rose-700', 'dot' => 'bg-rose-500', 'label' => __('Failed')],
                        default => ['classes' => 'text-sky-800', 'dot' => 'bg-sky-500', 'label' => __('Working')],
                    };
                @endphp
                <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-white px-2.5 py-1 text-[11px] font-medium ring-1 ring-brand-ink/10 {{ $statusPill['classes'] }}">
                    @if ($engineInFlight)
                        <x-spinner variant="forest" size="sm" />
                    @else
                        <span aria-hidden="true" class="inline-block h-1.5 w-1.5 rounded-full {{ $statusPill['dot'] }}"></span>
                    @endif
                    {{ $statusPill['label'] }}
                </span>
            @endif
        </div>

        <div class="px-6 py-6 sm:px-8">
            @if (($comingSoonEngines[$engine] ?? false) && ! $engineRow)
                {{-- Coming soon: engine is gated behind database.{engine}. MySQL /
                     PostgreSQL stay installable; this engine shows a teaser instead
                     of the install affordance until platform admin flips the flag on.
                     The Info tab still describes the engine so operators can evaluate
                     it now. --}}
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sand/60 text-brand-moss ring-1 ring-brand-ink/10">
                        <x-heroicon-o-clock class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Coming soon') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __(':engine support is on the way', ['engine' => $dbEngineInfoForTab['label']]) }}</h3>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                            {{ $dbEngineInfoForTab['tagline'] }}
                            {{ __('One-click install on this server is coming soon — for now, MySQL and PostgreSQL are the supported relational engines. See the Info tab for details on :engine.', ['engine' => $dbEngineInfoForTab['label']]) }}
                        </p>
                        <div class="mt-4 flex flex-wrap items-center gap-3">
                            <button
                                type="button"
                                wire:click="setEngineSubtab('info')"
                                class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40"
                            >
                                <x-heroicon-o-information-circle class="h-4 w-4" aria-hidden="true" />
                                {{ __('Learn more') }}
                            </button>
                            <span class="inline-flex cursor-not-allowed items-center gap-2 rounded-lg bg-brand-forest/30 px-4 py-2 text-sm font-medium text-white opacity-70">
                                <x-heroicon-o-no-symbol class="h-4 w-4" aria-hidden="true" />
                                {{ __('Install :engine', ['engine' => $dbEngineInfoForTab['label']]) }}
                            </span>
                        </div>
                    </div>
                </div>
            @elseif (! $engineRow)
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
                        class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40"
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
                        class="inline-flex items-center gap-1.5 rounded-md border border-rose-300 bg-white px-3 py-1.5 text-xs font-semibold text-rose-800 shadow-sm hover:bg-rose-50"
                    >
                        <x-heroicon-o-arrow-uturn-left class="h-3.5 w-3.5" />
                        {{ __('Stop & revert') }}
                    </button>
                </div>
            @else
                <dl class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Status') }}</dt>
                        <dd class="mt-1 text-sm text-brand-ink">{{ ucfirst($engineRow->status) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Version') }}</dt>
                        <dd class="mt-1 font-mono text-sm text-brand-ink">{{ $engineRow->version ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Port') }}</dt>
                        <dd class="mt-1 font-mono text-sm text-brand-ink">{{ $engineRow->port }}</dd>
                    </div>
                </dl>
                @if ($engineRow->status === \App\Models\ServerDatabaseEngine::STATUS_FAILED && filled($engineRow->error_message))
                    <p class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs leading-relaxed text-rose-800">
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
                    <div class="mt-5 flex flex-wrap gap-2">
                        <button
                            type="button"
                            wire:click="openConfirmActionModal('uninstallDatabaseEngine', ['{{ $engine }}'], @js(__('Uninstall :engine', ['engine' => $dbEngineInfoForTab['label']])), @js(__('apt purge will remove the engine and its data dirs from the server. Existing tracked databases stay in Dply but won\'t have a live engine to talk to.')), @js(__('Uninstall')), true)"
                            class="rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-100"
                        >
                            {{ __('Uninstall') }}
                        </button>
                        @if ($showEngineWorkspace)
                            <button
                                type="button"
                                wire:click="setEngineSubtab('databases')"
                                class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40"
                            >
                                <x-heroicon-o-circle-stack class="h-4 w-4" aria-hidden="true" />
                                {{ __('Manage databases') }}
                            </button>
                        @endif
                    </div>
                @endif
            @endif
        </div>
    </div>
@elseif ($engine === 'sqlite' && ($capabilities['sqlite'] ?? false))
    <div class="{{ $card }} overflow-hidden px-6 py-6 sm:px-8">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="max-w-2xl">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Engine') }}</p>
                <h3 class="mt-0.5 text-lg font-semibold text-brand-ink">{{ $dbEngineInfoForTab['label'] }}</h3>
                <p class="mt-2 text-sm leading-relaxed text-brand-moss">{{ $dbEngineInfoForTab['description'] }}</p>
            </div>
            <span class="inline-flex shrink-0 items-center rounded-full bg-emerald-50 px-2.5 py-1 text-[11px] font-semibold text-emerald-700">{{ __('Active') }}</span>
        </div>
        <div class="mt-5">
            <button
                type="button"
                wire:click="setEngineSubtab('databases')"
                class="inline-flex items-center gap-2 rounded-lg bg-brand-forest px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-brand-forest/90"
            >
                <x-heroicon-o-circle-stack class="h-4 w-4" aria-hidden="true" />
                {{ __('Manage databases') }}
            </button>
        </div>
    </div>
@endif
