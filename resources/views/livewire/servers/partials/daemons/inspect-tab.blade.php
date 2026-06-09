            <div class="{{ $card }}">
                <div class="flex min-w-0 items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-8">
                    <x-icon-badge>
                        <x-heroicon-o-magnifying-glass class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Inspect') }}</p>
                        <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Supervisor on the server') }}</h2>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                            {{ __('Read-only diagnostics: systemd unit status, supervisorctl version + status, and the tail of the supervisord daemon log. When the login user is not root, Dply uses sudo -n (passwordless sudo must be allowed, same as provisioning).') }}
                        </p>
                    </div>
                </div>
                <div class="space-y-4 p-6 sm:p-8">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-sm text-brand-moss">{{ __('Runs systemctl + supervisorctl + tails the daemon log over SSH.') }}</p>
                        <button
                            type="button"
                            wire:click="loadSupervisorInspect"
                            wire:loading.attr="disabled"
                            @disabled($supervisor_installed === false)
                            class="inline-flex shrink-0 items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <span wire:loading.remove wire:target="loadSupervisorInspect" class="inline-flex items-center gap-1.5">
                                <x-heroicon-o-arrow-path class="h-4 w-4" />
                                {{ __('Load status') }}
                            </span>
                            <span wire:loading wire:target="loadSupervisorInspect" class="inline-flex items-center gap-2">
                                <x-spinner variant="forest" />
                                {{ __('Loading…') }}
                            </span>
                        </button>
                    </div>
                    <div class="max-h-[min(55vh,28rem)] overflow-auto rounded-xl border border-brand-ink/10 bg-zinc-950">
                        <pre class="whitespace-pre-wrap break-words p-4 font-mono text-xs leading-relaxed text-zinc-100">@if ($inspect_supervisor_body !== null){{ $inspect_supervisor_body }}@else{{ __('Click “Load status” to fetch supervisorctl output.') }}@endif</pre>
                    </div>
                </div>
            </div>
