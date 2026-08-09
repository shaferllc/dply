            <div class="{{ $card }}">
                <x-workspace-panel-head
                    dense
                    icon="heroicon-o-server"
                    :title="__('Supervisor service (systemd)')"
                    :note="__('Start, stop, or restart the Supervisor daemon on the guest. This is separate from individual program start/stop on the Programs tab. Unit: :unit (override with DPLY_SUPERVISOR_SYSTEMD_UNIT).', ['unit' => config('sites.supervisor_systemd_unit', 'supervisor')])"
                    class="border-b border-brand-ink/10"
                />
                {{-- The stop-halts-everything warning stays out of the head's note:
                     a dense note truncates, and this one must not be the part that
                     gets cut off. --}}
                <p class="flex items-center gap-1.5 border-b border-amber-200/80 bg-amber-50/60 px-4 py-2 text-xs font-medium text-amber-900 sm:px-5">
                    <x-heroicon-m-exclamation-triangle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    {{ __('Stopping the service halts all Supervisor-managed workers until you start it again.') }}
                </p>
                <div class="space-y-5 px-4 py-3.5 sm:px-5">

                    {{-- Diagnostics --}}
                    <div>
                        <p class="mb-2 text-xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Diagnostics') }}</p>
                        <div class="flex flex-wrap gap-2">
                            <button
                                type="button"
                                wire:click="supervisorServiceAction('status')"
                                wire:loading.attr="disabled"
                                wire:target="supervisorServiceAction"
                                @disabled($supervisor_installed !== true)
                                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <x-heroicon-o-information-circle class="h-4 w-4" />
                                {{ __('Status') }}
                            </button>
                            <button
                                type="button"
                                wire:click="supervisorServiceAction('is-active')"
                                wire:loading.attr="disabled"
                                wire:target="supervisorServiceAction"
                                @disabled($supervisor_installed !== true)
                                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-50"
                            >
                                <x-heroicon-o-signal class="h-4 w-4" />
                                {{ __('Is active?') }}
                            </button>
                            <button
                                type="button"
                                wire:click="supervisorServiceAction('is-enabled')"
                                wire:loading.attr="disabled"
                                wire:target="supervisorServiceAction"
                                @disabled($supervisor_installed !== true)
                                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-50"
                            >
                                <x-heroicon-o-check-circle class="h-4 w-4" />
                                {{ __('Boot enabled?') }}
                            </button>
                        </div>
                    </div>

                    {{-- Lifecycle --}}
                    <div>
                        <p class="mb-2 text-xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Lifecycle') }}</p>
                        <div class="flex flex-wrap gap-2">
                            {{-- Show Start when inactive or unknown; show Stop when active only --}}
                            @if ($supervisor_service_state !== 'active')
                                <button
                                    type="button"
                                    wire:click="supervisorServiceAction('start')"
                                    wire:loading.attr="disabled"
                                    wire:target="supervisorServiceAction"
                                    @disabled($supervisor_installed !== true)
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-900 shadow-sm hover:bg-emerald-100 disabled:opacity-50"
                                >
                                    <x-heroicon-o-play class="h-4 w-4" />
                                    {{ __('Start') }}
                                </button>
                            @else
                                <button
                                    type="button"
                                    wire:click="supervisorServiceAction('stop')"
                                    wire:loading.attr="disabled"
                                    wire:target="supervisorServiceAction"
                                    @disabled($supervisor_installed !== true)
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-950 shadow-sm hover:bg-amber-100 disabled:opacity-50"
                                >
                                    <x-heroicon-o-stop class="h-4 w-4" />
                                    {{ __('Stop') }}
                                </button>
                            @endif
                            <button
                                type="button"
                                wire:click="supervisorServiceAction('restart')"
                                wire:loading.attr="disabled"
                                wire:target="supervisorServiceAction"
                                @disabled($supervisor_installed !== true)
                                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-50"
                            >
                                <x-heroicon-o-arrow-path class="h-4 w-4" />
                                {{ __('Restart') }}
                            </button>
                            <button
                                type="button"
                                wire:click="supervisorServiceAction('reload')"
                                wire:loading.attr="disabled"
                                wire:target="supervisorServiceAction"
                                @disabled($supervisor_installed !== true)
                                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-50"
                            >
                                <x-heroicon-o-arrow-uturn-left class="h-4 w-4" />
                                {{ __('Reload') }}
                            </button>
                        </div>
                        <p class="mt-2 inline-flex items-center gap-1 text-xs {{ $supervisor_service_state === 'active' ? 'text-emerald-700' : 'text-brand-mist' }}">
                            <span class="inline-block h-1.5 w-1.5 rounded-full {{ $supervisor_service_state === 'active' ? 'bg-emerald-500' : 'bg-brand-mist' }}"></span>
                            @if ($supervisor_service_state === 'active')
                                {{ __('Active') }}
                            @elseif ($supervisor_service_state === 'inactive')
                                {{ __('Inactive') }}
                            @else
                                {{ __('Checking…') }}
                            @endif
                        </p>
                    </div>

                    {{-- Boot --}}
                    <div>
                        <p class="mb-2 text-xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Boot') }}</p>
                        <div class="flex flex-wrap gap-2">
                            {{-- Show Enable when disabled or unknown; show Disable when enabled only --}}
                            @if ($supervisor_boot_state !== 'enabled')
                                <button
                                    type="button"
                                    wire:click="supervisorServiceAction('enable')"
                                    wire:loading.attr="disabled"
                                    wire:target="supervisorServiceAction"
                                    @disabled($supervisor_installed !== true)
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-50"
                                >
                                    <x-heroicon-o-bolt class="h-4 w-4" />
                                    {{ __('Enable on boot') }}
                                </button>
                            @else
                                <button
                                    type="button"
                                    wire:click="supervisorServiceAction('disable')"
                                    wire:loading.attr="disabled"
                                    wire:target="supervisorServiceAction"
                                    @disabled($supervisor_installed !== true)
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-50"
                                >
                                    <x-heroicon-o-bolt-slash class="h-4 w-4" />
                                    {{ __('Disable on boot') }}
                                </button>
                            @endif
                        </div>
                        @if ($supervisor_boot_state !== null)
                            <p class="mt-2 text-xs text-brand-mist">
                                {{ $supervisor_boot_state === 'enabled' ? __('Starts automatically on boot') : __('Does not start on boot') }}
                            </p>
                        @endif
                    </div>

                    {{-- Output --}}
                    <pre class="max-h-[min(50vh,24rem)] overflow-auto whitespace-pre-wrap break-all rounded-xl bg-zinc-950 px-4 py-3 font-mono text-xs leading-relaxed text-zinc-100 [scrollbar-color:rgb(82_82_91/0.45)_transparent]">{{ $supervisor_service_output !== '' ? $supervisor_service_output : __('Run a command above. Output appears here.') }}</pre>
                </div>
            </div>
