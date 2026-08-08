@php
    /**
     * Grouped bottom-right dock: Deploys + Feedback + Console (when available).
     *
     * @var \App\Models\Server|null $routeServer
     * @var bool $hideDrawer
     */
    $consoleLive = ! $hideDrawer && workspace_console_active();
    $consolePreview = ! $hideDrawer && ! $consoleLive && workspace_console_preview_active();
    $showConsoleChip = $consoleLive || $consolePreview;
@endphp

<div
    class="fixed bottom-4 end-4 z-40"
    x-data="{
        consoleOpen: false,
        feedbackOpen: false,
        deployConsoleOpen: false,
        init() {
            this.consoleOpen = localStorage.getItem('dply.consoleDrawer.open') === '1';
            window.addEventListener('dply-console-drawer-opened', () => { this.consoleOpen = true; });
            window.addEventListener('dply-console-drawer-closed', () => { this.consoleOpen = false; });
            window.addEventListener('dply-feedback-opened', () => { this.feedbackOpen = true; });
            window.addEventListener('dply-feedback-closed', () => { this.feedbackOpen = false; });
            window.addEventListener('dply-deploy-console-opened', () => { this.deployConsoleOpen = true; });
            window.addEventListener('dply-deploy-console-closed', () => { this.deployConsoleOpen = false; });
        },
        openDeploys() {
            window.dispatchEvent(new CustomEvent('dply-open-deploy-status'));
        },
        openFeedback() {
            window.dispatchEvent(new CustomEvent('dply-open-feedback'));
        },
        toggleConsole() {
            @if ($consoleLive)
                window.dispatchEvent(new CustomEvent('dply-toggle-console-drawer'));
            @elseif ($consolePreview)
                window.dispatchEvent(new CustomEvent('dply-open-console-preview'));
            @endif
        },
    }"
    {{-- Hide while any dock-related drawer covers this corner. --}}
    x-show="!feedbackOpen && !consoleOpen && !deployConsoleOpen"
    x-cloak
>
    <div
        class="inline-flex items-center gap-0.5 rounded-full border border-brand-ink/15 bg-white/95 p-1 shadow-lg shadow-brand-ink/10 backdrop-blur"
        role="toolbar"
        aria-label="{{ __('Quick actions') }}"
    >
        <button
            type="button"
            x-on:click="openDeploys()"
            class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold text-brand-ink transition hover:bg-brand-sand/50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-sage/40"
            title="{{ __('Open deploy status') }}"
        >
            <x-heroicon-o-rocket-launch class="h-4 w-4 shrink-0 text-brand-moss" aria-hidden="true" />
            {{ __('Deploys') }}
        </button>

        <span class="mx-0.5 h-4 w-px shrink-0 bg-brand-ink/10" aria-hidden="true"></span>

        <button
            type="button"
            x-on:click="openFeedback()"
            class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold text-brand-ink transition hover:bg-brand-sand/50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-sage/40"
            title="{{ __('Send feedback or report a bug') }}"
        >
            <x-heroicon-o-chat-bubble-left-right class="h-4 w-4 shrink-0 text-brand-moss" aria-hidden="true" />
            {{ __('Feedback') }}
        </button>

        @if ($showConsoleChip)
            <span
                x-show="!consoleOpen"
                class="mx-0.5 h-4 w-px shrink-0 bg-brand-ink/10"
                aria-hidden="true"
            ></span>

            <button
                type="button"
                x-on:click="toggleConsole()"
                x-show="!consoleOpen"
                @class([
                    'inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-sage/40',
                    'bg-brand-ink text-white hover:bg-brand-ink/90' => $consoleLive,
                    'text-brand-ink hover:bg-brand-sand/50' => $consolePreview,
                ])
                title="{{ $consoleLive
                    ? __('Open SSH console — backtick (`) toggles')
                    : __('Browser console — preview') }}"
            >
                <x-heroicon-o-command-line @class([
                    'h-4 w-4 shrink-0',
                    'text-white/90' => $consoleLive,
                    'text-brand-moss' => $consolePreview,
                ]) aria-hidden="true" />
                {{ __('Console') }}
                @if ($consoleLive)
                    <kbd class="ms-0.5 hidden items-center rounded bg-white/15 px-1 py-0.5 text-[10px] font-medium sm:inline-flex">`</kbd>
                @endif
            </button>
        @endif
    </div>
</div>

@if ($consolePreview)
    <div
        x-data="{ previewOpen: false }"
        x-on:keydown.escape.window="previewOpen = false"
        x-on:dply-open-console-preview.window="previewOpen = true"
    >
        <div
            x-show="previewOpen"
            x-cloak
            class="fixed inset-0 z-[100] overflow-y-auto"
            role="dialog"
            aria-modal="true"
            aria-labelledby="console-preview-modal-title"
        >
            <div class="fixed inset-0 bg-brand-ink/50 backdrop-blur-sm" x-on:click="previewOpen = false"></div>
            <div class="relative flex min-h-full items-center justify-center px-4 py-10 sm:px-6">
                <div class="relative w-full max-w-xl">
                    <button
                        type="button"
                        x-on:click="previewOpen = false"
                        class="absolute -top-3 end-0 z-10 inline-flex items-center gap-1.5 rounded-full border border-brand-ink/10 bg-white px-3 py-1.5 text-xs font-semibold text-brand-moss shadow-sm hover:bg-brand-sand/40 hover:text-brand-ink"
                        aria-label="{{ __('Close') }}"
                    >
                        <x-heroicon-o-x-mark class="h-4 w-4" />
                        {{ __('Close') }}
                    </button>
                    <x-console-preview-panel compact :server="$routeServer" />
                </div>
            </div>
        </div>
    </div>
@endif
