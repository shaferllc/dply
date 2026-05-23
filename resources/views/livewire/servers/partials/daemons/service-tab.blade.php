            <div class="{{ $card }}">
                <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                    <h2 class="text-sm font-semibold text-brand-ink">{{ __('Supervisor service (systemd)') }}</h2>
                    <p class="mt-1 text-xs text-brand-moss leading-relaxed">
                        {{ __('Start, stop, or restart the Supervisor daemon on the guest. This is separate from individual program start/stop on the Programs tab. Unit: :unit (override with DPLY_SUPERVISOR_SYSTEMD_UNIT).', ['unit' => config('sites.supervisor_systemd_unit', 'supervisor')]) }}
                    </p>
                    <p class="mt-2 text-xs font-medium text-amber-900/90">
                        {{ __('Stopping the service halts all Supervisor-managed workers until you start it again.') }}
                    </p>
                </div>
                <div class="space-y-4 p-6 sm:p-8">
                    <div class="flex flex-wrap gap-2">
                        <button
                            type="button"
                            wire:click="supervisorServiceAction('status')"
                            wire:loading.attr="disabled"
                            wire:target="supervisorServiceAction"
                            @disabled($supervisor_installed !== true)
                            class="rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                        >{{ __('Status') }}</button>
                        <button
                            type="button"
                            wire:click="supervisorServiceAction('is-active')"
                            wire:loading.attr="disabled"
                            @disabled($supervisor_installed !== true)
                            class="rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-50"
                        >{{ __('Is active?') }}</button>
                        <button
                            type="button"
                            wire:click="supervisorServiceAction('is-enabled')"
                            wire:loading.attr="disabled"
                            @disabled($supervisor_installed !== true)
                            class="rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-50"
                        >{{ __('Boot enabled?') }}</button>
                    </div>
                    <p class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Lifecycle') }}</p>
                    <div class="flex flex-wrap gap-2">
                        <button
                            type="button"
                            wire:click="supervisorServiceAction('start')"
                            wire:loading.attr="disabled"
                            wire:target="supervisorServiceAction"
                            @disabled($supervisor_installed !== true)
                            class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-900 shadow-sm hover:bg-emerald-100 disabled:opacity-50"
                        >{{ __('Start') }}</button>
                        <button
                            type="button"
                            wire:click="supervisorServiceAction('stop')"
                            wire:loading.attr="disabled"
                            wire:target="supervisorServiceAction"
                            @disabled($supervisor_installed !== true)
                            class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-950 shadow-sm hover:bg-amber-100 disabled:opacity-50"
                        >{{ __('Stop') }}</button>
                        <button
                            type="button"
                            wire:click="supervisorServiceAction('restart')"
                            wire:loading.attr="disabled"
                            wire:target="supervisorServiceAction"
                            @disabled($supervisor_installed !== true)
                            class="rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-50"
                        >{{ __('Restart') }}</button>
                        <button
                            type="button"
                            wire:click="supervisorServiceAction('reload')"
                            wire:loading.attr="disabled"
                            wire:target="supervisorServiceAction"
                            @disabled($supervisor_installed !== true)
                            class="rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-50"
                        >{{ __('Reload') }}</button>
                    </div>
                    <p class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Boot') }}</p>
                    <div class="flex flex-wrap gap-2">
                        <button
                            type="button"
                            wire:click="supervisorServiceAction('enable')"
                            wire:loading.attr="disabled"
                            wire:target="supervisorServiceAction"
                            @disabled($supervisor_installed !== true)
                            class="rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-50"
                        >{{ __('Enable on boot') }}</button>
                        <button
                            type="button"
                            wire:click="supervisorServiceAction('disable')"
                            wire:loading.attr="disabled"
                            wire:target="supervisorServiceAction"
                            @disabled($supervisor_installed !== true)
                            class="rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-50"
                        >{{ __('Disable on boot') }}</button>
                    </div>
                    <pre class="max-h-[min(50vh,24rem)] overflow-auto whitespace-pre-wrap break-all rounded-xl bg-zinc-950 px-4 py-3 font-mono text-xs leading-relaxed text-zinc-100 [scrollbar-color:rgb(82_82_91/0.45)_transparent]">{{ $supervisor_service_output !== '' ? $supervisor_service_output : __('Run a command above. Output appears here.') }}</pre>
                </div>
            </div>
