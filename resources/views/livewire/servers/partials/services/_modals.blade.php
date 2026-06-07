        @if ($showSystemdActionConfirm)
            @php
                $kind = $systemdActionConfirmKind;
                $unit = $systemdActionConfirmUnit;
                $isBulk = str_starts_with($kind, 'bulk-');
                $bulkCount = $isBulk ? count($systemdSelectedList ?? []) : 0;
                $config = match ($kind) {
                    'start' => [
                        'title' => __('Start service'),
                        'description' => __('Starts the unit via systemctl on the server.'),
                        'icon' => 'heroicon-o-play-circle',
                        'tone' => 'emerald',
                        'confirm' => __('Start service'),
                        'destructive' => false,
                    ],
                    'restart' => [
                        'title' => __('Restart service'),
                        'description' => __('Stops then starts the unit. Connections may briefly drop while the service comes back up.'),
                        'icon' => 'heroicon-o-arrow-path',
                        'tone' => 'amber',
                        'confirm' => __('Restart service'),
                        'destructive' => false,
                    ],
                    'stop' => [
                        'title' => __('Stop service'),
                        'description' => __('Stops the unit immediately. Anything depending on it will lose its connection until you start it again.'),
                        'icon' => 'heroicon-o-stop-circle',
                        'tone' => 'rose',
                        'confirm' => __('Stop service'),
                        'destructive' => true,
                    ],
                    'reload' => [
                        'title' => __('Reload service'),
                        'description' => __('Reapplies the unit\'s configuration without a full restart, when the unit supports reload.'),
                        'icon' => 'heroicon-o-arrow-path-rounded-square',
                        'tone' => 'sky',
                        'confirm' => __('Reload service'),
                        'destructive' => false,
                    ],
                    'enable' => [
                        'title' => __('Enable at boot'),
                        'description' => __('Marks the unit to start automatically when the server boots. Does not start it now.'),
                        'icon' => 'heroicon-o-bolt',
                        'tone' => 'emerald',
                        'confirm' => __('Enable at boot'),
                        'destructive' => false,
                    ],
                    'disable' => [
                        'title' => __('Disable at boot'),
                        'description' => __('Removes the boot-time auto-start. The unit may keep running until you stop it.'),
                        'icon' => 'heroicon-o-no-symbol',
                        'tone' => 'rose',
                        'confirm' => __('Disable at boot'),
                        'destructive' => true,
                    ],
                    'bulk-restart' => [
                        'title' => __('Restart selected services'),
                        'description' => __('Each unit is restarted in sequence. Connections to those units may briefly drop while they come back up.'),
                        'icon' => 'heroicon-o-arrow-path',
                        'tone' => 'amber',
                        'confirm' => trans_choice('Restart :count service|Restart :count services', $bulkCount, ['count' => $bulkCount]),
                        'destructive' => false,
                    ],
                    'bulk-stop' => [
                        'title' => __('Stop selected services'),
                        'description' => __('Each unit is stopped in sequence. Anything depending on them loses its connection until you start them again.'),
                        'icon' => 'heroicon-o-stop-circle',
                        'tone' => 'rose',
                        'confirm' => trans_choice('Stop :count service|Stop :count services', $bulkCount, ['count' => $bulkCount]),
                        'destructive' => true,
                    ],
                    'remove-custom' => [
                        'title' => __('Remove custom unit'),
                        'description' => __('Removes this unit from Dply\'s custom-services allowlist. The unit itself stays on the server; only Dply\'s tracking is dropped.'),
                        'icon' => 'heroicon-o-trash',
                        'tone' => 'rose',
                        'confirm' => __('Remove from list'),
                        'destructive' => true,
                    ],
                    default => [
                        'title' => __('Confirm action'),
                        'description' => '',
                        'icon' => 'heroicon-o-question-mark-circle',
                        'tone' => 'sand',
                        'confirm' => __('Confirm'),
                        'destructive' => false,
                    ],
                };
                $iconRing = match ($config['tone']) {
                    'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
                    'amber' => 'bg-amber-50 text-amber-800 ring-amber-200',
                    'rose' => 'bg-rose-50 text-rose-700 ring-rose-200',
                    'sky' => 'bg-sky-50 text-sky-700 ring-sky-200',
                    default => 'bg-brand-sand/40 text-brand-moss ring-brand-ink/10',
                };
                $confirmBtn = $config['destructive']
                    ? 'inline-flex items-center justify-center gap-2 rounded-lg bg-rose-600 px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-white shadow-sm hover:bg-rose-700 transition-colors disabled:cursor-not-allowed disabled:opacity-50'
                    : 'inline-flex items-center justify-center gap-2 rounded-lg bg-brand-ink px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-brand-cream shadow-sm hover:bg-brand-forest transition-colors disabled:cursor-not-allowed disabled:opacity-50';
                $cancelBtn = 'inline-flex items-center justify-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-brand-ink shadow-sm hover:bg-brand-sand/50 transition-colors';
            @endphp
            @teleport('body')
                <div
                    class="fixed inset-0 isolate z-[100] overflow-y-auto"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="systemd-action-confirm-title"
                    x-data="{ close() { $wire.closeSystemdActionConfirm() } }"
                    x-init="setTimeout(() => $refs.confirm?.focus(), 50)"
                    x-on:keydown.escape.window="close()"
                >
                    <div class="fixed inset-0 z-0 bg-brand-ink/50 backdrop-blur-sm" x-on:click="close()"></div>
                    <div class="relative z-10 flex min-h-full items-center justify-center px-4 py-10 sm:px-6">
                        <div class="relative w-full max-w-lg dply-modal-panel">
                            <div class="flex items-start gap-3 px-6 py-5 sm:px-7">
                                <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl ring-1 {{ $iconRing }}">
                                    <x-dynamic-component :component="$config['icon']" class="h-5 w-5" aria-hidden="true" />
                                </span>
                                <div class="min-w-0 flex-1">
                                    <h2 id="systemd-action-confirm-title" class="text-base font-semibold text-brand-ink">{{ $config['title'] }}</h2>
                                    @if ($isBulk)
                                        <p class="mt-1 text-xs font-medium text-brand-moss">
                                            {{ trans_choice('{0} no units selected|{1} :count selected unit|[2,*] :count selected units', $bulkCount, ['count' => $bulkCount]) }}
                                        </p>
                                    @elseif ($unit !== '')
                                        <p class="mt-1 inline-flex items-center gap-1 rounded-md bg-brand-sand/40 px-2 py-0.5 font-mono text-[11px] text-brand-ink ring-1 ring-brand-ink/10">{{ $unit }}</p>
                                    @endif
                                </div>
                            </div>

                            @php
                                $confirmRow = $this->systemdActionConfirmRow();
                            @endphp
                            @if ($confirmRow !== null)
                                @php
                                    $rowActive = (string) ($confirmRow['active'] ?? '');
                                    $rowSub = (string) ($confirmRow['sub'] ?? '');
                                    $rowFailed = ! empty($confirmRow['is_failed']);
                                    $rowPid = trim((string) ($confirmRow['main_pid'] ?? ''));
                                    $rowBoot = trim((string) ($confirmRow['boot_state'] ?? ''));
                                    $rowVersion = trim((string) ($confirmRow['version'] ?? ''));
                                    $rowTs = (string) ($confirmRow['ts'] ?? '');
                                    $rowTsRel = null;
                                    $rowTsCompact = null;
                                    if ($rowTs !== '' && $rowTs !== 'n/a') {
                                        try {
                                            $rowTsRel = \Carbon\Carbon::parse($rowTs)->diffForHumans();
                                            $rowTsCompact = \Carbon\Carbon::parse($rowTs)->format('M j, H:i');
                                        } catch (\Throwable) {
                                            $rowTsRel = null;
                                            $rowTsCompact = null;
                                        }
                                    }
                                    $stateBadge = match (true) {
                                        $rowFailed => 'bg-rose-50 text-rose-800 ring-rose-200',
                                        $rowActive === 'active' => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
                                        $rowActive === 'activating', $rowActive === 'reloading' => 'bg-amber-50 text-amber-900 ring-amber-200',
                                        default => 'bg-brand-sand/50 text-brand-moss ring-brand-ink/10',
                                    };
                                    $stateLabel = $rowFailed ? __('Failed') : ($rowActive !== '' ? ucfirst($rowActive) : __('Unknown'));
                                @endphp
                                <div class="border-t border-brand-ink/10 px-6 py-4 sm:px-7">
                                    <p class="mb-3 text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Current state') }}</p>
                                    <div class="grid grid-cols-2 gap-3 text-xs sm:grid-cols-4">
                                        <div>
                                            <p class="text-[10px] uppercase tracking-wide text-brand-mist">{{ __('Active') }}</p>
                                            <p class="mt-1">
                                                <span class="inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-[11px] font-semibold ring-1 {{ $stateBadge }}">
                                                    {{ $stateLabel }}@if ($rowSub !== '' && $rowSub !== $rowActive) <span class="font-normal">/ {{ $rowSub }}</span>@endif
                                                </span>
                                            </p>
                                        </div>
                                        <div>
                                            <p class="text-[10px] uppercase tracking-wide text-brand-mist">{{ __('PID') }}</p>
                                            <p class="mt-1 font-mono text-[11px] text-brand-ink">{{ $rowPid !== '' && $rowPid !== '0' ? $rowPid : '—' }}</p>
                                        </div>
                                        <div>
                                            <p class="text-[10px] uppercase tracking-wide text-brand-mist">{{ __('Boot') }}</p>
                                            <p class="mt-1 font-mono text-[11px] text-brand-ink">{{ $rowBoot !== '' ? $rowBoot : '—' }}</p>
                                        </div>
                                        <div>
                                            <p class="text-[10px] uppercase tracking-wide text-brand-mist">{{ __('Last change') }}</p>
                                            <p class="mt-1 text-[11px] text-brand-ink" title="{{ $rowTs }}">
                                                @if ($rowTsCompact)
                                                    {{ $rowTsCompact }}
                                                    @if ($rowTsRel)
                                                        <span class="text-brand-mist">· {{ $rowTsRel }}</span>
                                                    @endif
                                                @else
                                                    —
                                                @endif
                                            </p>
                                        </div>
                                    </div>
                                    @if ($rowVersion !== '')
                                        <p class="mt-3 text-[11px] text-brand-mist">{{ __('Version') }}: <span class="font-mono text-brand-moss">{{ $rowVersion }}</span></p>
                                    @endif
                                    @if (! empty($confirmRow['standby_reason']) && in_array($kind, ['start', 'enable'], true))
                                        <p class="mt-3 rounded-lg border border-amber-200/80 bg-amber-50 px-3 py-2.5 text-xs leading-relaxed text-amber-950">
                                            {{ $confirmRow['standby_reason'] }}
                                        </p>
                                    @endif
                                </div>
                            @endif

                            <div class="border-t border-brand-ink/10 bg-brand-sand/15 px-6 py-4 sm:px-7">
                                <p class="text-sm leading-relaxed text-brand-moss">{{ $config['description'] }}</p>
                                <ul class="mt-3 space-y-1 text-[11px] text-brand-mist">
                                    <li class="inline-flex items-center gap-1.5">
                                        <x-heroicon-m-command-line class="h-3 w-3 shrink-0 text-brand-mist" aria-hidden="true" />
                                        {{ __('Runs as root over SSH on :host', ['host' => $server->getSshConnectionString()]) }}
                                    </li>
                                    @if (! in_array($kind, ['remove-custom'], true))
                                        <li class="inline-flex items-center gap-1.5">
                                            <x-heroicon-m-arrow-path class="h-3 w-3 shrink-0 text-brand-mist" aria-hidden="true" />
                                            {{ __('Inventory refreshes once the action completes') }}
                                        </li>
                                    @endif
                                </ul>
                            </div>

                            @php $previewCommand = $this->systemdActionConfirmCommand(); @endphp
                            @if ($previewCommand !== null)
                                <div class="border-t border-brand-ink/10 px-6 py-4 sm:px-7">
                                    <div class="mb-2 flex items-center justify-between gap-2">
                                        <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Console — what will run') }}</p>
                                        <span class="inline-flex items-center gap-1 text-[10px] text-brand-mist">
                                            <x-heroicon-m-arrow-down-on-square class="h-3 w-3" aria-hidden="true" />
                                            ssh
                                        </span>
                                    </div>
                                    <pre class="overflow-x-auto rounded-lg border border-brand-ink/15 bg-brand-ink/95 p-3 font-mono text-[11px] leading-relaxed text-emerald-100"><code>{{ $previewCommand }}</code></pre>
                                </div>
                            @endif

                            <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 px-6 py-4 sm:px-7">
                                <button type="button" x-on:click="close()" class="{{ $cancelBtn }}">
                                    {{ __('Cancel') }}
                                </button>
                                <button
                                    type="button"
                                    x-ref="confirm"
                                    wire:click="confirmSystemdAction"
                                    wire:loading.attr="disabled"
                                    class="{{ $confirmBtn }}"
                                >
                                    <x-dynamic-component :component="$config['icon']" class="h-4 w-4 shrink-0" aria-hidden="true" wire:loading.remove wire:target="confirmSystemdAction" />
                                    <span wire:loading wire:target="confirmSystemdAction" class="inline-flex h-3.5 w-3.5 shrink-0 animate-spin rounded-full border-2 border-current/40 border-t-current" aria-hidden="true"></span>
                                    <span wire:loading.remove wire:target="confirmSystemdAction">{{ $config['confirm'] }}</span>
                                    <span wire:loading wire:target="confirmSystemdAction">{{ __('Working…') }}</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            @endteleport
        @endif
        @if ($showCustomSystemdModal)
            <div
                class="fixed inset-0 z-50 flex items-end justify-center p-4 sm:items-center sm:p-6"
                role="dialog"
                aria-modal="true"
                aria-labelledby="custom-systemd-heading"
            >
                <div class="fixed inset-0 bg-brand-ink/40 backdrop-blur-[1px]" wire:click="closeCustomSystemdModal"></div>
                <div class="relative z-10 w-full max-w-lg rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-xl">
                    <h2 id="custom-systemd-heading" class="text-lg font-semibold text-brand-ink">{{ __('Custom systemd units') }}</h2>
                    <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                        {{ __('Add unit names (for example mysql or myapp-worker). They are allowlisted for inventory and actions on this server only. Use full names like mysql.service or short names — we normalize to .service.') }}
                    </p>
                    <div class="mt-4 flex gap-2">
                        <input
                            type="text"
                            wire:model="newCustomSystemdUnit"
                            wire:keydown.enter.prevent="addCustomSystemdUnit"
                            placeholder="{{ __('e.g. mysql or redis-server') }}"
                            class="w-full rounded-lg border border-brand-ink/15 px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                        />
                        <x-primary-button
                            size="sm"
                            type="button"
                            wire:click="addCustomSystemdUnit"
                            wire:loading.attr="disabled"
                            wire:target="addCustomSystemdUnit"
                            @disabled($isDeployer)
                            class="shrink-0"
                        >
                            <span wire:loading wire:target="addCustomSystemdUnit" class="inline-flex h-3.5 w-3.5 shrink-0 animate-spin rounded-full border-2 border-brand-cream/40 border-t-brand-cream" aria-hidden="true"></span>
                            <span wire:loading.remove wire:target="addCustomSystemdUnit">{{ __('Add') }}</span>
                            <span wire:loading wire:target="addCustomSystemdUnit">{{ __('Working…') }}</span>
                        </x-primary-button>
                    </div>
                    @if ($customMetaList !== [])
                        <ul class="mt-4 max-h-48 space-y-2 overflow-y-auto rounded-xl border border-brand-ink/10 p-3 text-sm">
                            @foreach ($customMetaList as $cu)
                                <li class="flex items-center justify-between gap-2 font-mono text-xs text-brand-ink">
                                    <span>{{ $cu }}</span>
                                    <button
                                        type="button"
                                        wire:click="openSystemdActionConfirm('remove-custom', @js($cu))"
                                        wire:loading.attr="disabled"
                                        wire:target="openSystemdActionConfirm"
                                        class="inline-flex shrink-0 items-center gap-1.5 text-xs font-semibold text-red-700 hover:text-red-900 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        <span wire:loading wire:target="openSystemdActionConfirm" class="inline-flex h-3 w-3 shrink-0 animate-spin rounded-full border-2 border-red-300 border-t-red-700" aria-hidden="true"></span>
                                        <span wire:loading.remove wire:target="openSystemdActionConfirm">{{ __('Remove') }}</span>
                                        <span wire:loading wire:target="openSystemdActionConfirm">{{ __('Working…') }}</span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="mt-4 text-sm text-brand-moss">{{ __('No custom units yet.') }}</p>
                    @endif
                    <div class="mt-6 flex justify-end gap-2">
                        <x-secondary-button size="sm" type="button" wire:click="closeCustomSystemdModal">
                            {{ __('Done') }}
                        </x-secondary-button>
                    </div>
                </div>
            </div>
        @endif
        @if ($showSystemdStatusModal)
            <div
                class="fixed inset-0 z-50 flex items-end justify-center p-4 sm:items-center sm:p-6"
                role="dialog"
                aria-modal="true"
                aria-labelledby="systemd-status-modal-heading"
            >
                <div class="fixed inset-0 bg-brand-ink/40 backdrop-blur-[1px]" wire:click="closeSystemdStatusModal"></div>
                <div class="relative z-10 max-h-[min(92vh,52rem)] w-full max-w-[min(96vw,72rem)] overflow-y-auto overscroll-contain dply-modal-panel [-webkit-overflow-scrolling:touch]">
                    <div class="sticky top-0 z-[1] flex flex-col gap-3 border-b border-brand-ink/10 bg-white px-4 py-4 sm:px-6 sm:py-5">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex min-w-0 items-start gap-3">
                                <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-sand/40 text-brand-forest ring-1 ring-brand-ink/10">
                                    @if ($systemdStatusModalView === 'logs')
                                        <x-heroicon-o-document-text class="h-4 w-4" aria-hidden="true" />
                                    @else
                                        <x-heroicon-o-eye class="h-4 w-4" aria-hidden="true" />
                                    @endif
                                </span>
                                <div class="min-w-0">
                                    <h2 id="systemd-status-modal-heading" class="text-base font-semibold text-brand-ink">
                                        {{ $systemdStatusModalView === 'logs' ? __('Service logs') : __('Service status') }}
                                    </h2>
                                    <p class="mt-0.5 font-mono text-xs text-brand-moss break-all">{{ $systemdStatusModalUnit }}</p>
                                </div>
                            </div>
                            <div class="flex shrink-0 flex-wrap items-center justify-end gap-2">
                                <x-secondary-button
                                    size="sm"
                                    type="button"
                                    wire:click="fetchSystemdModalStatus"
                                    wire:loading.attr="disabled"
                                    wire:target="fetchSystemdModalStatus"
                                    @disabled(! $opsReady || ($deployerSystemdLocked ?? true) || $systemdStatusModalLoading)
                                    class="!inline-flex !items-center !gap-1.5 !py-2 !text-[11px]"
                                >
                                    <x-heroicon-o-arrow-path class="h-4 w-4 shrink-0 text-brand-ink/80" wire:loading.class="animate-spin" wire:target="fetchSystemdModalStatus" />
                                    <span wire:loading.remove wire:target="fetchSystemdModalStatus">{{ __('Refresh') }}</span>
                                    <span wire:loading wire:target="fetchSystemdModalStatus">{{ __('Working…') }}</span>
                                </x-secondary-button>
                                <x-secondary-button size="sm" type="button" wire:click="closeSystemdStatusModal" class="!py-2 !text-[11px]">
                                    {{ __('Close') }}
                                </x-secondary-button>
                            </div>
                        </div>
                    </div>
                    <div class="px-4 py-4 sm:px-6 sm:py-5">
                        @if ($systemdStatusModalLoading)
                            <p class="text-xs font-medium text-brand-ink">{{ $systemdStatusModalView === 'logs' ? __('Fetching journalctl logs…') : __('Fetching systemctl status…') }}</p>
                            <p class="mt-0.5 text-[11px] text-brand-moss">{{ __('This can take a few seconds over SSH.') }}</p>
                        @endif
                        @if ($systemdStatusModalError)
                            <div class="mb-3 rounded-lg border border-red-200/80 bg-red-50/90 px-3 py-2 text-xs text-red-900 whitespace-pre-wrap break-words [overflow-wrap:anywhere]">{{ $systemdStatusModalError }}</div>
                        @endif
                        @if ($systemdStatusModalOutput !== '')
                            @php
                                // Lightweight syntax-highlighter for the
                                // `systemctl status` text dump. Escape
                                // the buffer first, then layer span
                                // classes on top so the whole thing
                                // remains safe to render with {!! !!}.
                                $statusOut = e($systemdStatusModalOutput);

                                // The leading state bullet ("● unit"
                                // active, "×" failed) — color the dot
                                // before the unit name.
                                $statusOut = preg_replace_callback(
                                    '/^(\s*)([●×])(\s+\S+)/m',
                                    function (array $m): string {
                                        $color = $m[2] === '×' ? 'text-red-500' : 'text-emerald-500';

                                        return $m[1].'<span class="'.$color.' font-semibold">'.$m[2].'</span>'.$m[3];
                                    },
                                    $statusOut
                                ) ?? $statusOut;

                                // State words inside parens — "(running)"
                                // green, "(dead)"/"(failed)" red, others
                                // amber for transitions.
                                $statusOut = preg_replace_callback(
                                    '/\b(active)\s+\((running|listening|exited|waiting)\)/i',
                                    fn (array $m): string => '<span class="text-emerald-700 font-semibold">'.$m[0].'</span>',
                                    $statusOut
                                ) ?? $statusOut;
                                $statusOut = preg_replace_callback(
                                    '/\b(inactive|failed)\s+\(([^)]+)\)/i',
                                    fn (array $m): string => '<span class="text-red-700 font-semibold">'.$m[0].'</span>',
                                    $statusOut
                                ) ?? $statusOut;
                                $statusOut = preg_replace_callback(
                                    '/\b(activating|deactivating|reloading)\s+\(([^)]+)\)/i',
                                    fn (array $m): string => '<span class="text-amber-700 font-semibold">'.$m[0].'</span>',
                                    $statusOut
                                ) ?? $statusOut;

                                // "enabled" / "disabled" / "static" /
                                // "masked" descriptors after Loaded:
                                $statusOut = preg_replace_callback(
                                    '/(;\s*)(enabled|enabled-runtime|alias|generated|indirect)\b/',
                                    fn (array $m): string => $m[1].'<span class="text-emerald-700">'.$m[2].'</span>',
                                    $statusOut
                                ) ?? $statusOut;
                                $statusOut = preg_replace_callback(
                                    '/(;\s*)(disabled|masked|bad)\b/',
                                    fn (array $m): string => $m[1].'<span class="text-red-700">'.$m[2].'</span>',
                                    $statusOut
                                ) ?? $statusOut;
                                $statusOut = preg_replace_callback(
                                    '/(;\s*)(static|preset:\s*\S+)/',
                                    fn (array $m): string => $m[1].'<span class="text-zinc-500">'.$m[2].'</span>',
                                    $statusOut
                                ) ?? $statusOut;

                                // Field labels at the start of a line:
                                // Loaded, Active, Main PID, Tasks, Memory,
                                // CPU, CGroup, Docs, Status, etc.
                                $statusOut = preg_replace_callback(
                                    '/^(\s*)(Loaded|Active|Main PID|Tasks|Memory|CPU|CGroup|Docs|Status|Process|TriggeredBy|IP|IO|Drop-In):/m',
                                    fn (array $m): string => $m[1].'<span class="text-zinc-500">'.$m[2].':</span>',
                                    $statusOut
                                ) ?? $statusOut;

                                // WARNING / ERROR severity tags in journal
                                // tail lines.
                                $statusOut = preg_replace_callback(
                                    '/\b(WARNING|ERROR|CRITICAL|FATAL)\b/',
                                    fn (array $m): string => '<span class="font-semibold '.match (strtoupper($m[1])) {
                                        'WARNING' => 'text-amber-700',
                                        default => 'text-red-700',
                                    }.'">'.$m[1].'</span>',
                                    $statusOut
                                ) ?? $statusOut;

                                // Mute timestamps prefixing journal lines:
                                // "May 04 19:28:09 silver-stag …".
                                $statusOut = preg_replace_callback(
                                    '/^([A-Z][a-z]{2}\s+\d{1,2}\s+\d{2}:\d{2}:\d{2})(\s+\S+)/m',
                                    fn (array $m): string => '<span class="text-zinc-400">'.$m[1].'</span><span class="text-zinc-500">'.$m[2].'</span>',
                                    $statusOut
                                ) ?? $statusOut;
                            @endphp
                            <div
                                x-data="{
                                    copied: false,
                                    async copy() {
                                        try {
                                            await navigator.clipboard.writeText(this.$refs.statusRaw.textContent);
                                            this.copied = true;
                                            setTimeout(() => { this.copied = false; }, 1500);
                                        } catch (e) { /* clipboard blocked — silent */ }
                                    },
                                }"
                                class="rounded-xl border border-brand-ink/15 bg-zinc-50 p-3 shadow-inner"
                            >
                                <div class="mb-2 flex items-center justify-between gap-3">
                                    <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-ink">{{ $systemdStatusModalView === 'logs' ? __('journalctl -u') : __('systemctl status') }}</p>
                                    <button
                                        type="button"
                                        @click="copy()"
                                        class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-brand-ink shadow-sm hover:bg-brand-sand/40"
                                    >
                                        <template x-if="copied">
                                            <span class="inline-flex items-center gap-1 text-emerald-700">
                                                <x-heroicon-o-check class="h-3 w-3 shrink-0" aria-hidden="true" />
                                                {{ __('Copied') }}
                                            </span>
                                        </template>
                                        <template x-if="! copied">
                                            <span class="inline-flex items-center gap-1">
                                                <x-heroicon-o-clipboard class="h-3 w-3 shrink-0 text-brand-ink/70" aria-hidden="true" />
                                                {{ __('Copy') }}
                                            </span>
                                        </template>
                                    </button>
                                </div>
                                {{-- Hidden raw copy target keeps the copied
                                     payload free of the colorization spans
                                     while the visible <pre> renders the
                                     highlighted version. --}}
                                <pre x-ref="statusRaw" class="hidden">{{ $systemdStatusModalOutput }}</pre>
                                <pre class="font-mono text-[11px] leading-snug whitespace-pre-wrap break-words text-zinc-900 [overflow-wrap:anywhere]">{!! $statusOut !!}</pre>
                            </div>
                        @elseif (! $systemdStatusModalLoading && $systemdStatusModalError === null)
                            <p class="text-xs text-brand-moss">{{ __('No output yet. Choose Refresh status.') }}</p>
                        @endif
                    </div>
                </div>
            </div>
        @endif
        @if ($showSystemdNotifyModal)
            @php
                $systemdKindLabels = \App\Support\ServerSystemdServiceNotificationKeys::kindLabels();
            @endphp
            <div
                class="fixed inset-0 z-50 flex items-end justify-center p-4 sm:items-center sm:p-6"
                role="dialog"
                aria-modal="true"
                aria-labelledby="systemd-notify-modal-heading"
            >
                <div class="fixed inset-0 bg-brand-ink/40 backdrop-blur-[1px]" wire:click="closeSystemdNotifyModal"></div>
                <div class="relative z-10 max-h-[min(92vh,42rem)] w-full max-w-[min(96vw,48rem)] overflow-y-auto overscroll-contain dply-modal-panel">
                    <div class="border-b border-brand-ink/10 px-4 py-4 sm:px-6 sm:py-5">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h2 id="systemd-notify-modal-heading" class="text-base font-semibold text-brand-ink">{{ __('Notify on service changes') }}</h2>
                                <p class="mt-0.5 font-mono text-xs text-brand-moss break-all">{{ $systemdNotifyUnit }}</p>
                            </div>
                            <x-secondary-button size="sm" type="button" wire:click="closeSystemdNotifyModal" class="!py-2 !text-[11px]">
                                {{ __('Close') }}
                            </x-secondary-button>
                        </div>
                    </div>
                    <div class="px-4 py-4 sm:px-6 sm:py-5">
                        <p class="text-xs text-brand-moss leading-snug">
                            {{ __('When background inventory detects a change, notify the channels you choose. Tick the events you care about per channel, then save.') }}
                        </p>
                        <p class="mt-2 text-[11px] text-brand-moss leading-snug">
                            {{ __('Tip: “Restarted” can be noisy during deploys; “Stopped” and “State change” are usually enough.') }}
                        </p>
                        @if ($systemdNotifyChannelRows === [])
                            <p class="mt-3 text-xs text-brand-moss">{{ __('No notification channels are available to your account in this organization yet.') }}</p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                @if ($server->organization_id)
                                    <a
                                        href="{{ route('organizations.notification-channels', $server->organization_id) }}"
                                        wire:navigate
                                        class="inline-flex items-center justify-center gap-2 rounded-lg bg-brand-ink px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-brand-cream shadow-sm hover:bg-brand-forest transition-colors disabled:cursor-not-allowed disabled:opacity-50 !py-2 !text-[11px]"
                                    >
                                        {{ __('Add organization channels') }}
                                    </a>
                                @endif
                                <x-secondary-button size="sm" href="{{ route('profile.notification-channels') }}" wire:navigate class="!py-2 !text-[11px]">
                                    {{ __('My notification channels') }}
                                </x-secondary-button>
                            </div>
                        @else
                            <div class="mt-3 overflow-x-auto rounded-xl border border-brand-ink/10">
                                <table class="min-w-full text-left text-xs">
                                    <thead class="bg-brand-sand/40 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">
                                        <tr>
                                            <th class="px-2 py-2">{{ __('Channel') }}</th>
                                            @foreach ($systemdKindLabels as $kind => $_label)
                                                <th class="px-2 py-2 text-center">{{ $_label }}</th>
                                            @endforeach
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-brand-ink/10 bg-white">
                                        @foreach ($systemdNotifyChannelRows as $chRow)
                                            <tr wire:key="svc-notify-{{ $chRow['id'] }}">
                                                <td class="px-2 py-2 font-medium text-brand-ink">{{ $chRow['label'] }}</td>
                                                @foreach ($systemdKindLabels as $kind => $_label)
                                                    <td class="px-2 py-2 text-center align-middle">
                                                        <input
                                                            type="checkbox"
                                                            class="rounded border-brand-ink/20 text-brand-forest focus:ring-brand-sage"
                                                            wire:model.live="systemdNotifyMatrix.{{ $chRow['id'] }}.{{ $kind }}"
                                                            @disabled($isDeployer)
                                                        />
                                                    </td>
                                                @endforeach
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            <div class="mt-3 flex flex-wrap justify-end gap-2">
                                <x-primary-button
                                    size="sm"
                                    type="button"
                                    wire:click="saveSystemdNotifyPreferences"
                                    wire:loading.attr="disabled"
                                    wire:target="saveSystemdNotifyPreferences"
                                    @disabled($isDeployer)
                                    class="!py-2 !text-[11px]"
                                >
                                    <span wire:loading wire:target="saveSystemdNotifyPreferences" class="inline-flex h-3.5 w-3.5 shrink-0 animate-spin rounded-full border-2 border-brand-cream/40 border-t-brand-cream" aria-hidden="true"></span>
                                    <span wire:loading.remove wire:target="saveSystemdNotifyPreferences">{{ __('Save alert routing') }}</span>
                                    <span wire:loading wire:target="saveSystemdNotifyPreferences">{{ __('Working…') }}</span>
                                </x-primary-button>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @endif
