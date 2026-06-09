@if ($logsModalContainerId)
    <div class="fixed inset-0 z-50 overflow-y-auto overscroll-y-contain" role="dialog" aria-modal="true" aria-labelledby="docker-logs-title" wire:key="docker-logs-modal">
        <div class="fixed inset-0 bg-brand-ink/30" wire:click="closeContainerLogsModal"></div>
        <div class="relative z-10 flex min-h-full justify-center px-4 py-10 sm:px-6">
            <div class="my-auto flex w-full max-w-4xl flex-col dply-modal-panel overflow-hidden shadow-xl" @click.stop>
                <div class="flex shrink-0 items-start justify-between gap-3 border-b border-brand-ink/10 px-6 py-5">
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Container logs') }}</p>
                        <h2 id="docker-logs-title" class="mt-1 font-mono text-sm font-semibold text-brand-ink">{{ $logsModalContainerName }}</h2>
                    </div>
                    <button type="button" wire:click="closeContainerLogsModal" class="rounded-lg p-1 text-brand-mist hover:bg-brand-sand/40 hover:text-brand-ink" aria-label="{{ __('Close') }}">
                        <x-heroicon-o-x-mark class="h-5 w-5" />
                    </button>
                </div>
                <div class="max-h-[28rem] overflow-auto bg-brand-ink px-4 py-4">
                    @if ($logsModalLoading)
                        <div class="flex items-center gap-2 text-sm text-brand-cream/80">
                            <x-spinner variant="cream" size="sm" />
                            {{ __('Loading logs…') }}
                        </div>
                    @elseif ($logsModalError)
                        <p class="text-sm text-rose-300">{{ $logsModalError }}</p>
                    @else
                        <pre class="whitespace-pre-wrap break-all font-mono text-xs leading-relaxed text-brand-cream/95">{{ $logsModalContent !== '' ? $logsModalContent : __('No log output.') }}</pre>
                    @endif
                </div>
                <div class="flex justify-end border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                    <x-secondary-button type="button" wire:click="closeContainerLogsModal">{{ __('Close') }}</x-secondary-button>
                </div>
            </div>
        </div>
    </div>
@endif

@if ($inspectModalContainerId)
    <div class="fixed inset-0 z-50 overflow-y-auto overscroll-y-contain" role="dialog" aria-modal="true" aria-labelledby="docker-inspect-title" wire:key="docker-inspect-modal">
        <div class="fixed inset-0 bg-brand-ink/30" wire:click="closeContainerInspectModal"></div>
        <div class="relative z-10 flex min-h-full justify-center px-4 py-10 sm:px-6">
            <div class="my-auto flex w-full max-w-4xl flex-col dply-modal-panel overflow-hidden shadow-xl" @click.stop>
                <div class="flex shrink-0 items-start justify-between gap-3 border-b border-brand-ink/10 px-6 py-5">
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Container inspect') }}</p>
                        <h2 id="docker-inspect-title" class="mt-1 font-mono text-sm font-semibold text-brand-ink">{{ $inspectModalContainerName }}</h2>
                    </div>
                    <button type="button" wire:click="closeContainerInspectModal" class="rounded-lg p-1 text-brand-mist hover:bg-brand-sand/40 hover:text-brand-ink" aria-label="{{ __('Close') }}">
                        <x-heroicon-o-x-mark class="h-5 w-5" />
                    </button>
                </div>
                <div class="max-h-[28rem] overflow-auto bg-brand-ink px-4 py-4">
                    @if ($inspectModalLoading)
                        <div class="flex items-center gap-2 text-sm text-brand-cream/80">
                            <x-spinner variant="cream" size="sm" />
                            {{ __('Loading inspect JSON…') }}
                        </div>
                    @elseif ($inspectModalError)
                        <p class="text-sm text-rose-300">{{ $inspectModalError }}</p>
                    @else
                        <pre class="whitespace-pre-wrap break-all font-mono text-xs leading-relaxed text-brand-cream/95">{{ $inspectModalContent }}</pre>
                    @endif
                </div>
                <div class="flex justify-end border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                    <x-secondary-button type="button" wire:click="closeContainerInspectModal">{{ __('Close') }}</x-secondary-button>
                </div>
            </div>
        </div>
    </div>
@endif

@if ($execModalContainerId)
    <div class="fixed inset-0 z-50 overflow-y-auto overscroll-y-contain" role="dialog" aria-modal="true" aria-labelledby="docker-exec-title" wire:key="docker-exec-modal">
        <div class="fixed inset-0 bg-brand-ink/30" wire:click="closeContainerExecModal"></div>
        <div class="relative z-10 flex min-h-full justify-center px-4 py-10 sm:px-6">
            <div class="my-auto flex w-full max-w-lg flex-col dply-modal-panel overflow-hidden shadow-xl" @click.stop>
                <div class="flex shrink-0 items-start justify-between gap-3 border-b border-brand-ink/10 px-6 py-5">
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Run command') }}</p>
                        <h2 id="docker-exec-title" class="mt-1 font-mono text-sm font-semibold text-brand-ink">{{ $execModalContainerName }}</h2>
                        <p class="mt-1 text-xs text-brand-moss">{{ __('Runs docker exec … sh -c over SSH. Single line only.') }}</p>
                    </div>
                    <button type="button" wire:click="closeContainerExecModal" class="rounded-lg p-1 text-brand-mist hover:bg-brand-sand/40 hover:text-brand-ink" aria-label="{{ __('Close') }}">
                        <x-heroicon-o-x-mark class="h-5 w-5" />
                    </button>
                </div>
                <div class="space-y-4 px-6 py-5">
                    <div>
                        <label for="docker-exec-command" class="block text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Command') }}</label>
                        <input
                            id="docker-exec-command"
                            type="text"
                            wire:model="execModalCommand"
                            placeholder="php artisan migrate --force"
                            class="mt-2 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-sm text-brand-ink shadow-sm focus:border-brand-gold focus:outline-none focus:ring-2 focus:ring-brand-gold/30"
                        />
                    </div>
                </div>
                <div class="flex justify-end gap-2 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                    <x-secondary-button type="button" wire:click="closeContainerExecModal">{{ __('Cancel') }}</x-secondary-button>
                    <x-primary-button type="button" wire:click="submitContainerExec">{{ __('Run command') }}</x-primary-button>
                </div>
            </div>
        </div>
    </div>
@endif

@if ($shellModalContainerId)
    <div class="fixed inset-0 z-50 overflow-y-auto overscroll-y-contain" role="dialog" aria-modal="true" aria-labelledby="docker-shell-title" wire:key="docker-shell-modal">
        <div class="fixed inset-0 bg-brand-ink/30" wire:click="closeContainerShell"></div>
        <div class="relative z-10 flex min-h-full justify-center px-4 py-8 sm:px-6">
            <div class="my-auto flex w-full max-w-4xl flex-col dply-modal-panel overflow-hidden shadow-xl" @click.stop>
                <div class="flex shrink-0 flex-wrap items-start justify-between gap-3 border-b border-brand-ink/10 px-6 py-5">
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Container shell session') }}</p>
                        <h2 id="docker-shell-title" class="mt-1 font-mono text-sm font-semibold text-brand-ink">{{ $shellModalContainerName }}</h2>
                        <p class="mt-1 text-xs text-brand-moss">{{ __('Commands run via docker exec over SSH. History is kept until you close this panel.') }}</p>
                    </div>
                    <button type="button" wire:click="closeContainerShell" class="rounded-lg p-1 text-brand-mist hover:bg-brand-sand/40 hover:text-brand-ink" aria-label="{{ __('Close') }}">
                        <x-heroicon-o-x-mark class="h-5 w-5" />
                    </button>
                </div>

                <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Interactive shell on your machine') }}</p>
                    <p class="mt-1 text-xs leading-relaxed text-brand-moss">{{ __('Browser PTY is not available yet. Copy this command for a full docker exec -it session in your local terminal:') }}</p>
                    <div
                        class="mt-3 flex flex-wrap items-center gap-2"
                        x-data="{ copied: false, copy() { navigator.clipboard.writeText(@js($shellSshCommand)); this.copied = true; setTimeout(() => { this.copied = false; }, 1500); } }"
                    >
                        <code class="min-w-0 flex-1 break-all rounded-lg bg-brand-ink px-3 py-2 font-mono text-[11px] text-brand-cream">{{ $shellSshCommand }}</code>
                        <button type="button" x-on:click="copy()" class="inline-flex shrink-0 items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40">
                            <x-heroicon-o-clipboard class="h-4 w-4" aria-hidden="true" />
                            <span x-show="!copied">{{ __('Copy SSH command') }}</span>
                            <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                        </button>
                    </div>
                    @feature('workspace.run')
                        <div class="mt-3">
                            <a
                                href="{{ route('servers.run', ['server' => $server, 'container' => $shellModalContainerId, 'container_name' => $shellModalContainerName]) }}"
                                wire:navigate
                                class="inline-flex items-center gap-1.5 text-xs font-semibold text-brand-forest hover:underline"
                            >
                                {{ __('Open in Run workspace') }}
                                <x-heroicon-o-arrow-right class="h-4 w-4" aria-hidden="true" />
                            </a>
                        </div>
                    @endfeature
                </div>

                <div class="px-6 py-4">
                    <div class="mb-3 flex flex-wrap items-center gap-2">
                        <span class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Quick') }}</span>
                        @foreach ($shellQuickActions as $i => $action)
                            <button
                                type="button"
                                wire:click="runContainerShellQuickAction({{ $i }})"
                                wire:loading.attr="disabled"
                                wire:target="runContainerShellCommand,runContainerShellQuickAction"
                                class="inline-flex items-center rounded-md border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50"
                                title="{{ $action['cmd'] }}"
                            >
                                {{ $action['label'] }}
                            </button>
                        @endforeach
                        @if ($shellModalHistory !== [])
                            <button type="button" wire:click="clearContainerShellHistory" class="ml-auto text-xs font-medium text-brand-moss hover:text-brand-ink underline-offset-2 hover:underline">
                                {{ __('Clear history') }}
                            </button>
                        @endif
                    </div>

                    <x-console-terminal-shell
                        :prompt-user="'docker'"
                        :prompt-host="$shellModalContainerName"
                        max-height="360px"
                    >
                        <x-slot:body>
                            <div
                                x-data="{}"
                                x-init="$el.scrollTop = $el.scrollHeight"
                                x-on:scroll-console-bottom.window="$nextTick(() => { $el.scrollTop = $el.scrollHeight })"
                                class="space-y-3"
                            >
                                @if ($shellModalHistory === [])
                                    <p class="text-slate-400 italic">{{ __('Type a command below. Output from each docker exec run appears here.') }}</p>
                                @endif

                                @foreach ($shellModalHistory as $entry)
                                    <div>
                                        <div class="text-brand-sage">$ {{ $entry['cmd'] }}</div>
                                        @if ($entry['error'])
                                            <pre class="mt-1 whitespace-pre-wrap break-all text-rose-300">{{ $entry['error'] }}</pre>
                                        @elseif ($entry['out'] !== '')
                                            <pre class="mt-1 whitespace-pre-wrap break-all text-slate-100">{{ $entry['out'] }}</pre>
                                        @endif
                                        @if ($entry['exit'] !== null)
                                            <p class="mt-1 text-[10px] text-slate-500">{{ __('Exit :code', ['code' => $entry['exit']]) }}</p>
                                        @endif
                                    </div>
                                @endforeach

                                @if ($shellModalRunning)
                                    <div class="flex items-center gap-2 text-slate-400">
                                        <x-spinner variant="cream" size="sm" />
                                        {{ __('Running…') }}
                                    </div>
                                @endif
                            </div>
                        </x-slot:body>

                        <x-slot:footer>
                            <form wire:submit.prevent="runContainerShellCommand" class="flex items-center gap-2">
                                <span class="shrink-0 text-brand-sage">$</span>
                                <input
                                    type="text"
                                    wire:model="shellModalCommand"
                                    wire:loading.attr="disabled"
                                    wire:target="runContainerShellCommand,runContainerShellQuickAction"
                                    placeholder="ls -la"
                                    class="min-w-0 flex-1 border-0 bg-transparent font-mono text-sm text-slate-100 placeholder:text-slate-500 focus:outline-none focus:ring-0"
                                    autocomplete="off"
                                    spellcheck="false"
                                />
                                <button
                                    type="submit"
                                    wire:loading.attr="disabled"
                                    wire:target="runContainerShellCommand"
                                    class="inline-flex shrink-0 items-center rounded-md bg-brand-sage/20 px-2.5 py-1 text-xs font-semibold text-brand-sage hover:bg-brand-sage/30 disabled:opacity-50"
                                >
                                    {{ __('Run') }}
                                </button>
                            </form>
                            @if ($shellModalError)
                                <p class="mt-2 text-xs text-rose-300">{{ $shellModalError }}</p>
                            @endif
                        </x-slot:footer>
                    </x-console-terminal-shell>
                </div>

                <div class="flex justify-end border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                    <x-secondary-button type="button" wire:click="closeContainerShell">{{ __('Close') }}</x-secondary-button>
                </div>
            </div>
        </div>
    </div>
@endif

@if ($composeLogsModalProject)
    <div class="fixed inset-0 z-50 overflow-y-auto overscroll-y-contain" role="dialog" aria-modal="true" aria-labelledby="docker-compose-logs-title" wire:key="docker-compose-logs-modal">
        <div class="fixed inset-0 bg-brand-ink/30" wire:click="closeComposeLogsModal"></div>
        <div class="relative z-10 flex min-h-full justify-center px-4 py-10 sm:px-6">
            <div class="my-auto flex w-full max-w-4xl flex-col dply-modal-panel overflow-hidden shadow-xl" @click.stop>
                <div class="flex shrink-0 items-start justify-between gap-3 border-b border-brand-ink/10 px-6 py-5">
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Compose logs') }}</p>
                        <h2 id="docker-compose-logs-title" class="mt-1 font-mono text-sm font-semibold text-brand-ink">{{ $composeLogsModalProject }}</h2>
                    </div>
                    <button type="button" wire:click="closeComposeLogsModal" class="rounded-lg p-1 text-brand-mist hover:bg-brand-sand/40 hover:text-brand-ink" aria-label="{{ __('Close') }}">
                        <x-heroicon-o-x-mark class="h-5 w-5" />
                    </button>
                </div>
                <div class="max-h-[28rem] overflow-auto bg-brand-ink px-4 py-4">
                    @if ($composeLogsModalLoading)
                        <div class="flex items-center gap-2 text-sm text-brand-cream/80">
                            <x-spinner variant="cream" size="sm" />
                            {{ __('Loading logs…') }}
                        </div>
                    @elseif ($composeLogsModalError)
                        <p class="text-sm text-rose-300">{{ $composeLogsModalError }}</p>
                    @else
                        <pre class="whitespace-pre-wrap break-all font-mono text-xs leading-relaxed text-brand-cream/95">{{ $composeLogsModalContent !== '' ? $composeLogsModalContent : __('No log output.') }}</pre>
                    @endif
                </div>
                <div class="flex justify-end border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                    <x-secondary-button type="button" wire:click="closeComposeLogsModal">{{ __('Close') }}</x-secondary-button>
                </div>
            </div>
        </div>
    </div>
@endif
