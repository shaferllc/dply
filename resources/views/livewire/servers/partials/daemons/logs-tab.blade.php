            <div class="{{ $card }}">
                <x-workspace-panel-head
                    dense
                    icon="heroicon-o-document-text"
                    :title="__('Supervisord daemon log')"
                    :note="__('Tail of /var/log/supervisor/supervisord.log — supervisord itself logs here (startup, config reloads, child-process spawn failures). Independent of program stdout/stderr.')"
                    class="border-b border-brand-ink/10"
                />
                <div class="space-y-4 px-4 py-3.5 sm:px-5">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-sm text-brand-moss">{{ __('Loads the last 200 lines.') }}</p>
                        <button
                            type="button"
                            wire:click="tailSupervisordDaemonLog"
                            wire:loading.attr="disabled"
                            wire:target="tailSupervisordDaemonLog"
                            @disabled($supervisor_installed === false)
                            class="inline-flex shrink-0 items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <span wire:loading.remove wire:target="tailSupervisordDaemonLog" class="inline-flex items-center gap-1.5">
                                <x-heroicon-o-document-text class="h-4 w-4" />
                                {{ __('Tail daemon log') }}
                            </span>
                            <span wire:loading wire:target="tailSupervisordDaemonLog" class="inline-flex items-center gap-2">
                                <x-spinner variant="forest" />
                                {{ __('Loading…') }}
                            </span>
                        </button>
                    </div>
                    <pre class="max-h-[min(55vh,28rem)] overflow-auto whitespace-pre-wrap break-all rounded-xl bg-zinc-950 px-4 py-3 font-mono text-xs leading-relaxed text-zinc-100 [scrollbar-color:rgb(82_82_91/0.45)_transparent]">@if ($supervisord_log_body !== null){{ $supervisord_log_body }}@else{{ __('Click “Tail daemon log”.') }}@endif</pre>
                </div>
            </div>

            @if ($server->supervisorPrograms->isNotEmpty())
            <div class="{{ $card }}">
                <x-workspace-panel-head
                    dense
                    icon="heroicon-o-cpu-chip"
                    :title="__('Program logs')"
                    :note="__('Last lines from each program stdout log path (default under /tmp).')"
                    class="border-b border-brand-ink/10"
                />
                <div
                    class="space-y-4 px-4 py-3.5 sm:px-5"
                    @if ($log_follow_enabled && $log_tail_program_id)
                        wire:poll.3s="refreshLogTailFollow"
                    @endif
                >
                    <div class="flex flex-col gap-3 lg:flex-row lg:items-end">
                        <div class="min-w-0 flex-1">
                            <x-input-label for="log_tail_program_id" value="{{ __('Program') }}" />
                            <select
                                id="log_tail_program_id"
                                wire:model="log_tail_program_id"
                                class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm"
                            >
                                <option value="">{{ __('Select…') }}</option>
                                @foreach ($server->supervisorPrograms as $sp)
                                    <option value="{{ $sp->id }}">{{ $sp->slug }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <x-input-label for="log_which" value="{{ __('Stream') }}" />
                            <select
                                id="log_which"
                                wire:model="log_which"
                                class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm lg:w-40"
                            >
                                <option value="stdout">stdout</option>
                                <option value="stderr">stderr</option>
                            </select>
                        </div>
                        <label class="flex items-center gap-2 text-sm text-brand-ink lg:pb-2">
                            <input type="checkbox" wire:model.live="log_follow_enabled" class="rounded border-brand-ink/20 text-brand-forest focus:ring-brand-sage" />
                            {{ __('Follow (poll every 3s)') }}
                        </label>
                        <button
                            type="button"
                            wire:click="tailProgramLog"
                            wire:loading.attr="disabled"
                            wire:target="tailProgramLog"
                            @disabled($supervisor_installed !== true)
                            class="inline-flex shrink-0 items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <span wire:loading.remove wire:target="tailProgramLog">{{ __('Tail log') }}</span>
                            <span wire:loading wire:target="tailProgramLog" class="inline-flex items-center gap-2">
                                <x-spinner variant="forest" />
                                {{ __('Loading…') }}
                            </span>
                        </button>
                    </div>
                    <pre class="max-h-[min(55vh,28rem)] overflow-auto whitespace-pre-wrap break-all rounded-xl bg-zinc-950 px-4 py-3 font-mono text-xs leading-relaxed text-zinc-100 [scrollbar-color:rgb(82_82_91/0.45)_transparent]">{{ $log_tail_body !== '' ? $log_tail_body : __('Choose a program and tail the log.') }}</pre>
                </div>
            </div>
            @endif
