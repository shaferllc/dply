            <div class="{{ $card }}">
                <div class="p-6 sm:p-8">
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('New Supervisor program') }}</h2>
                    <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                        {{ __('Each program becomes a conf file on the server. After saving here, use “Sync Supervisor on server” to write files and reload Supervisor.') }}
                    </p>

                    <div class="mt-6">
                        <x-input-label for="supervisor_preset_picker" value="{{ __('Start from a preset (optional)') }}" />
                        <div class="mt-1 flex items-stretch gap-2">
                            <select
                                id="supervisor_preset_picker"
                                x-data
                                x-on:change="if ($event.target.value) { $wire.applySupervisorPreset($event.target.value); $event.target.value = ''; }"
                                class="block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30 sm:max-w-md"
                            >
                                <option value="">{{ __('— Pick a preset to fill the form —') }}</option>
                                @foreach ($supervisorPresets as $preset)
                                    <option value="{{ $preset['value'] }}">{{ $preset['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <p class="mt-1 text-xs text-brand-moss">{{ __('Selecting a preset fills the form below — you can still tweak anything before saving.') }}</p>
                    </div>

                    <form id="daemon-program-form" wire:submit="saveSupervisorProgram" class="mt-6 space-y-6">
                        @if ($editing_program_id)
                            <div class="rounded-xl border border-amber-300/80 bg-amber-50/80 px-4 py-3 text-sm text-amber-950">
                                {{ __('Editing an existing program. Save to update Dply; sync to apply on the server.') }}
                                <button type="button" wire:click="cancelEditProgram" class="ml-2 font-semibold underline">{{ __('Cancel') }}</button>
                            </div>
                        @endif
                        <div>
                            <x-input-label for="new_sv_slug" value="{{ __('Program name (slug)') }}" />
                            <input
                                id="new_sv_slug"
                                type="text"
                                wire:model="new_sv_slug"
                                class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 font-mono text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                                placeholder="{{ __('e.g. horizon') }}"
                                autocomplete="off"
                            />
                            <x-input-error :messages="$errors->get('new_sv_slug')" class="mt-1" />
                        </div>

                        <div>
                            <x-input-label for="new_sv_site_id" value="{{ __('Related site (optional)') }}" />
                            <select
                                id="new_sv_site_id"
                                wire:model="new_sv_site_id"
                                class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                            >
                                <option value="">{{ __('None') }}</option>
                                @foreach ($sitesForServer as $st)
                                    <option value="{{ $st->id }}">{{ $st->name }}</option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-xs text-brand-moss">{{ __('Links this worker to a site for clarity and deploy restarts when enabled on the site.') }}</p>
                        </div>

                        @if ($new_sv_type === 'queue')
                            <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 px-4 py-4">
                                <p class="text-sm font-medium text-brand-ink">{{ __('Program command') }}</p>
                                <p class="mt-1 text-xs text-brand-moss">{{ __('Quick mode lets you write any raw command (queue:work, scheduler, custom binaries — anything Supervisor can run). Advanced mode is a Laravel queue builder that assembles a `php artisan queue:work` line for you.') }}</p>
                                <div class="mt-3 flex flex-wrap gap-4">
                                    {{-- Labels are intentionally swapped vs. the underlying values:
                                         the granular builder is "Advanced" (many knobs), and the
                                         single raw-command path is "Quick" — matches user expectation
                                         that "Quick" = least to fill in. Backend `queue_builder_mode`
                                         keeps its 'quick'/'advanced' values for compatibility. --}}
                                    <label class="inline-flex cursor-pointer items-center gap-2 text-sm">
                                        <input type="radio" wire:model.live="queue_builder_mode" value="advanced" class="rounded-full border-brand-mist text-brand-ink focus:ring-brand-sage" />
                                        <span class="text-brand-ink">{{ __('Quick') }}</span>
                                    </label>
                                    <label class="inline-flex cursor-pointer items-center gap-2 text-sm">
                                        <input type="radio" wire:model.live="queue_builder_mode" value="quick" class="rounded-full border-brand-mist text-brand-ink focus:ring-brand-sage" />
                                        <span class="text-brand-ink">{{ __('Advanced') }}</span>
                                    </label>
                                </div>

                                @if ($queue_builder_mode === 'quick')
                                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                                        <div>
                                            <x-input-label for="quick_php_binary" value="{{ __('PHP binary') }}" />
                                            <input id="quick_php_binary" type="text" wire:model="quick_php_binary" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-sm" placeholder="php" />
                                            <x-input-error :messages="$errors->get('quick_php_binary')" class="mt-1" />
                                        </div>
                                        <div>
                                            <x-input-label for="quick_queue_connection" value="{{ __('Connection (optional)') }}" />
                                            <input id="quick_queue_connection" type="text" wire:model="quick_queue_connection" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-sm" placeholder="database" />
                                            <x-input-error :messages="$errors->get('quick_queue_connection')" class="mt-1" />
                                        </div>
                                        <div>
                                            <x-input-label for="quick_queue_name" value="{{ __('Queue name') }}" />
                                            <input id="quick_queue_name" type="text" wire:model="quick_queue_name" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-sm" placeholder="default" />
                                            <x-input-error :messages="$errors->get('quick_queue_name')" class="mt-1" />
                                        </div>
                                        <div>
                                            <x-input-label for="quick_timeout" value="{{ __('Timeout (seconds per job)') }}" />
                                            <input id="quick_timeout" type="number" wire:model="quick_timeout" min="1" class="mt-1 block w-full max-w-[10rem] rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm" />
                                            <x-input-error :messages="$errors->get('quick_timeout')" class="mt-1" />
                                        </div>
                                        <div>
                                            <x-input-label for="quick_sleep" value="{{ __('Sleep when empty (seconds)') }}" />
                                            <input id="quick_sleep" type="number" wire:model="quick_sleep" min="0" class="mt-1 block w-full max-w-[10rem] rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm" />
                                            <x-input-error :messages="$errors->get('quick_sleep')" class="mt-1" />
                                        </div>
                                        <div>
                                            <x-input-label for="quick_tries" value="{{ __('Tries') }}" />
                                            <input id="quick_tries" type="number" wire:model="quick_tries" min="0" class="mt-1 block w-full max-w-[10rem] rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm" />
                                            <x-input-error :messages="$errors->get('quick_tries')" class="mt-1" />
                                        </div>
                                        <div>
                                            <x-input-label for="quick_backoff" value="{{ __('Backoff (seconds)') }}" />
                                            <input id="quick_backoff" type="number" wire:model="quick_backoff" min="0" class="mt-1 block w-full max-w-[10rem] rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm" />
                                            <x-input-error :messages="$errors->get('quick_backoff')" class="mt-1" />
                                        </div>
                                        <div>
                                            <x-input-label for="quick_memory" value="{{ __('Memory (MB)') }}" />
                                            <input id="quick_memory" type="number" wire:model="quick_memory" min="16" class="mt-1 block w-full max-w-[10rem] rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm" />
                                            <x-input-error :messages="$errors->get('quick_memory')" class="mt-1" />
                                        </div>
                                        <div>
                                            <x-input-label for="quick_max_time" value="{{ __('Max time (seconds)') }}" />
                                            <input id="quick_max_time" type="number" wire:model="quick_max_time" min="0" class="mt-1 block w-full max-w-[10rem] rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm" />
                                            <p class="mt-1 text-xs text-brand-moss">{{ __('Restarts the worker process periodically (`--max-time`).') }}</p>
                                            <x-input-error :messages="$errors->get('quick_max_time')" class="mt-1" />
                                        </div>
                                        <div class="sm:col-span-2">
                                            <x-input-label for="quick_app_env" value="{{ __('APP_ENV') }}" />
                                            <input id="quick_app_env" type="text" wire:model="quick_app_env" class="mt-1 block w-full max-w-md rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-sm" placeholder="production" />
                                            <x-input-error :messages="$errors->get('quick_app_env')" class="mt-1" />
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endif

                        <div @class(['hidden' => $new_sv_type === 'queue' && $queue_builder_mode === 'quick'])>
                            <div>
                                <x-input-label for="new_sv_command" value="{{ __('Command') }}" />
                                <textarea
                                    id="new_sv_command"
                                    wire:model="new_sv_command"
                                    rows="2"
                                    class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 font-mono text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                                    placeholder="php artisan horizon"
                                ></textarea>
                                <x-input-error :messages="$errors->get('new_sv_command')" class="mt-1" />
                            </div>

                            <div class="mt-6">
                                <x-input-label for="new_sv_directory" value="{{ __('Working directory') }}" />
                                <input
                                    id="new_sv_directory"
                                    type="text"
                                    wire:model="new_sv_directory"
                                    class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 font-mono text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                                    placeholder="/home/dply"
                                />
                                <p class="mt-1 text-xs text-brand-moss">{{ __('Supervisor `cd`s here before running the command. Defaults to the deploy user\'s home for server-only daemons. App-style presets (queue worker, Horizon, Octane, Reverb, schedule:work, Node, Sidekiq) point at /home/<user>/apps/<server>/current — change to a different app path if needed.') }}</p>
                                <x-input-error :messages="$errors->get('new_sv_directory')" class="mt-1" />
                            </div>

                            <div class="mt-6">
                                <x-input-label for="new_sv_user" value="{{ __('Run as user') }}" />
                                <input
                                    id="new_sv_user"
                                    type="text"
                                    wire:model="new_sv_user"
                                    class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 font-mono text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                                    placeholder="dply"
                                />
                                <p class="mt-1 text-xs text-brand-moss">{{ __('Defaults to the deploy user (dply). Use `root` for system services, or another user that owns the working directory.') }}</p>
                                <x-input-error :messages="$errors->get('new_sv_user')" class="mt-1" />
                            </div>
                        </div>

                        <div class="grid gap-6 sm:grid-cols-2">
                            <div>
                                <x-input-label for="new_sv_numprocs" value="{{ __('Processes') }}" />
                                <input
                                    id="new_sv_numprocs"
                                    type="number"
                                    wire:model="new_sv_numprocs"
                                    min="1"
                                    max="32"
                                    class="mt-1 block w-full max-w-[8rem] rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                                />
                                <p class="mt-1.5 text-xs text-brand-moss">{{ __('numprocs in Supervisor — usually 1 for Horizon; use multiple queue workers as separate programs or Horizon scaling.') }}</p>
                                <x-input-error :messages="$errors->get('new_sv_numprocs')" class="mt-1" />
                            </div>
                        </div>

                        <details class="rounded-xl border border-brand-ink/10 bg-brand-sand/20 px-4 py-3" @if ($advancedFormOpen) open @endif>
                            <summary class="cursor-pointer text-sm font-semibold text-brand-ink">
                                <span class="inline-flex items-center gap-1.5">
                                    <x-heroicon-o-chevron-down class="h-3.5 w-3.5 text-brand-mist" />
                                    {{ __('More advanced — env, logs, supervisor tuning') }}
                                </span>
                            </summary>
                            <div class="mt-4 space-y-5">
                                <div>
                                    <x-input-label for="new_sv_env_lines" value="{{ __('Environment (optional, KEY=value per line)') }}" />
                                    <textarea
                                        id="new_sv_env_lines"
                                        wire:model="new_sv_env_lines"
                                        rows="3"
                                        class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 font-mono text-xs text-brand-ink shadow-sm"
                                        placeholder="APP_ENV=production"
                                    ></textarea>
                                    <p class="mt-1 text-xs text-brand-moss">{{ __('Stored encrypted in Dply and written into the Supervisor program config.') }}</p>
                                    <x-input-error :messages="$errors->get('new_sv_env_lines')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label for="new_sv_stdout_logfile" value="{{ __('Custom stdout log path (optional)') }}" />
                                    <input
                                        id="new_sv_stdout_logfile"
                                        type="text"
                                        wire:model="new_sv_stdout_logfile"
                                        class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 font-mono text-sm text-brand-ink shadow-sm"
                                        placeholder="/var/log/dply-worker.log"
                                    />
                                    <p class="mt-1 text-xs text-brand-moss">{{ __('Every program is logged automatically — Supervisor writes stdout to /var/log/supervisor/<slug>-stdout-<pid>.log unless you override the path here.') }}</p>
                                    <x-input-error :messages="$errors->get('new_sv_stdout_logfile')" class="mt-1" />
                                </div>

                                <div class="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <x-input-label for="new_sv_priority" value="{{ __('priority (optional)') }}" />
                                        <input
                                            id="new_sv_priority"
                                            type="number"
                                            wire:model="new_sv_priority"
                                            min="1"
                                            max="999"
                                            class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 font-mono text-sm"
                                            placeholder="{{ __('omit for default') }}"
                                        />
                                        <x-input-error :messages="$errors->get('new_sv_priority')" class="mt-1" />
                                    </div>
                                    <div>
                                        <x-input-label for="new_sv_startsecs" value="{{ __('startsecs (optional)') }}" />
                                        <input
                                            id="new_sv_startsecs"
                                            type="number"
                                            wire:model="new_sv_startsecs"
                                            min="0"
                                            class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 font-mono text-sm"
                                            placeholder="1"
                                        />
                                    </div>
                                    <div>
                                        <x-input-label for="new_sv_stopwaitsecs" value="{{ __('stopwaitsecs (optional)') }}" />
                                        <input
                                            id="new_sv_stopwaitsecs"
                                            type="number"
                                            wire:model="new_sv_stopwaitsecs"
                                            min="0"
                                            class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 font-mono text-sm"
                                            placeholder="3600"
                                        />
                                    </div>
                                    <div>
                                        <x-input-label for="new_sv_autorestart" value="{{ __('autorestart (optional)') }}" />
                                        <input
                                            id="new_sv_autorestart"
                                            type="text"
                                            wire:model="new_sv_autorestart"
                                            class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 font-mono text-sm"
                                            placeholder="true, false, unexpected, or a number"
                                        />
                                    </div>
                                    <div class="sm:col-span-2">
                                        <label class="flex items-center gap-2 text-sm text-brand-ink">
                                            <input type="checkbox" wire:model.live="new_sv_redirect_stderr" class="rounded border-brand-ink/20 text-brand-forest focus:ring-brand-sage" />
                                            {{ __('Redirect stderr to stdout (Supervisor default)') }}
                                        </label>
                                    </div>
                                    @if (! $new_sv_redirect_stderr)
                                        <div class="sm:col-span-2">
                                            <x-input-label for="new_sv_stderr_logfile" value="{{ __('stderr log path') }}" />
                                            <input
                                                id="new_sv_stderr_logfile"
                                                type="text"
                                                wire:model="new_sv_stderr_logfile"
                                                class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 font-mono text-sm"
                                            />
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </details>
                    </form>

                    <div class="mt-8 border-t border-brand-ink/10 pt-8">
                        <h3 class="text-sm font-semibold text-brand-ink">{{ __('Organization templates') }}</h3>
                        <p class="mt-1 text-xs text-brand-moss">{{ __('Save the current form as a reusable template for other servers in this organization.') }}</p>
                        <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end">
                            <div class="min-w-0 flex-1">
                                <x-input-label for="template_save_name" value="{{ __('Template name') }}" />
                                <input
                                    id="template_save_name"
                                    type="text"
                                    wire:model="template_save_name"
                                    class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm"
                                    placeholder="{{ __('e.g. Production Horizon') }}"
                                />
                            </div>
                            <button
                                type="button"
                                wire:click="saveOrgTemplate"
                                wire:loading.attr="disabled"
                                class="inline-flex shrink-0 items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40"
                            >
                                {{ __('Save form as template') }}
                            </button>
                        </div>
                        @if ($orgTemplates->isNotEmpty())
                            <ul class="mt-4 divide-y divide-brand-ink/10 rounded-xl border border-brand-ink/10">
                                @foreach ($orgTemplates as $tpl)
                                    <li class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-sm">
                                        <span class="font-medium text-brand-ink">{{ $tpl->name }}</span>
                                        <span class="flex gap-2">
                                            <button type="button" wire:click="applyOrgTemplate('{{ $tpl->id }}')" class="text-brand-forest hover:underline">{{ __('Apply') }}</button>
                                            <button type="button" wire:click="openConfirmActionModal('deleteOrgTemplate', ['{{ $tpl->id }}'], @js(__('Delete template')), @js(__('Delete this template?')), @js(__('Delete')), true)" class="text-red-600 hover:underline">{{ __('Delete') }}</button>
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
                <div class="flex flex-col-reverse items-stretch justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4 sm:flex-row sm:items-center sm:justify-end sm:flex-wrap">
                    <button
                        type="button"
                        wire:click="restartAllPrograms({{ $restartAllConfirmMessage !== '' ? 'true' : 'false' }})"
                        @if ($restartAllConfirmMessage !== '')
                            wire:confirm="{{ $restartAllConfirmMessage }}"
                        @endif
                        wire:loading.attr="disabled"
                        wire:target="restartAllPrograms"
                        @disabled($supervisor_installed !== true)
                        class="inline-flex items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <span wire:loading.remove wire:target="restartAllPrograms">{{ __('Restart all programs') }}</span>
                        <span wire:loading wire:target="restartAllPrograms" class="inline-flex items-center gap-2 text-xs">
                            <x-spinner variant="forest" size="sm" />
                            {{ __('Working…') }}
                        </span>
                    </button>
                    <button
                        type="button"
                        wire:click="runPreflightPathCheck"
                        wire:loading.attr="disabled"
                        wire:target="runPreflightPathCheck"
                        @disabled($supervisor_installed !== true)
                        class="inline-flex items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {{ __('Check working directories') }}
                    </button>
                    <button
                        type="button"
                        wire:click="syncSupervisor"
                        wire:loading.attr="disabled"
                        @disabled($supervisor_installed !== true)
                        class="inline-flex items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {{ __('Sync Supervisor on server') }}
                    </button>
                    <x-primary-button type="submit" form="daemon-program-form" class="justify-center">
                        {{ $editing_program_id ? __('Update program') : __('Add program') }}
                    </x-primary-button>
                </div>
                @if ($preflight_messages !== [])
                    <div class="border-t border-brand-ink/10 bg-white px-6 py-4 sm:px-8">
                        <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Path check') }}</p>
                        <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-brand-ink">
                            @foreach ($preflight_messages as $msg)
                                <li>{{ $msg }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>

            <div class="{{ $card }}">
                <div class="flex flex-col gap-3 border-b border-brand-ink/10 px-6 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-8">
                    <div class="min-w-0">
                        <h2 class="text-xs font-semibold uppercase tracking-wider text-brand-mist">{{ __('Programs on this server') }}</h2>
                        @if ($contextSiteModel)
                            <fieldset class="mt-3 flex flex-wrap items-center gap-3 text-sm">
                                <legend class="sr-only">{{ __('Program list scope') }}</legend>
                                <span class="text-brand-moss">{{ __('Show') }}</span>
                                <label class="inline-flex cursor-pointer items-center gap-2">
                                    <input type="radio" wire:model.live="programs_list_scope" value="site" class="rounded-full border-brand-mist text-brand-ink focus:ring-brand-sage" />
                                    <span class="text-brand-ink">{{ __('This site only') }}</span>
                                </label>
                                <label class="inline-flex cursor-pointer items-center gap-2">
                                    <input type="radio" wire:model.live="programs_list_scope" value="all" class="rounded-full border-brand-mist text-brand-ink focus:ring-brand-sage" />
                                    <span class="text-brand-ink">{{ __('All programs on server') }}</span>
                                </label>
                            </fieldset>
                        @endif
                    </div>
                    <button
                        type="button"
                        wire:click="loadProgramStatuses"
                        wire:loading.attr="disabled"
                        wire:target="loadProgramStatuses"
                        @disabled($supervisor_installed !== true)
                        class="inline-flex shrink-0 items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-50"
                    >
                        {{ __('Refresh statuses') }}
                    </button>
                </div>
                @if ($server->supervisorPrograms->isEmpty())
                    <p class="px-6 py-10 text-center text-sm text-brand-moss sm:px-8">
                        {{ __('No programs yet. Add one above, then sync to write configs and reload Supervisor.') }}
                    </p>
                @elseif ($filteredSupervisorPrograms->isEmpty())
                    <p class="px-6 py-10 text-center text-sm text-brand-moss sm:px-8">
                        {{ __('No programs match this filter. Choose “All programs on server” or add a program linked to this site.') }}
                    </p>
                @else
                    <ul class="divide-y divide-brand-ink/10">
                        @foreach ($filteredSupervisorPrograms as $sp)
                            @php
                                $pst = $program_status_map[$sp->id]['state'] ?? 'unknown';
                                $badgeClass = $programStatusBadgeClass($pst);
                            @endphp
                            <li id="program-{{ $sp->id }}" class="relative flex flex-col scroll-mt-24 sm:flex-row">
                                <span
                                    @class([
                                        'absolute bottom-0 left-0 top-0 w-1',
                                        'bg-brand-forest' => $sp->is_active,
                                        'bg-brand-mist' => ! $sp->is_active,
                                    ])
                                    aria-hidden="true"
                                ></span>
                                <div class="min-w-0 flex-1 py-4 pl-5 pr-4 sm:py-5 sm:pl-6 sm:pr-6">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="font-mono text-sm font-semibold text-brand-ink">{{ $sp->slug }}</p>
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ring-1 {{ $badgeClass }}">{{ $pst }}</span>
                                    </div>
                                    <p class="mt-1 text-xs text-brand-moss">
                                        <span class="font-medium text-brand-ink/80">{{ $sp->program_type }}</span>
                                        · {{ __('user') }} {{ $sp->user }}
                                        · {{ __('numprocs') }} {{ $sp->numprocs }}
                                        @if ($sp->site_id && $sitesForServer->firstWhere('id', $sp->site_id))
                                            · {{ __('site') }} {{ $sitesForServer->firstWhere('id', $sp->site_id)->name }}
                                        @endif
                                    </p>
                                    <p class="mt-2 break-all font-mono text-xs leading-relaxed text-brand-moss">{{ $sp->command }}</p>
                                    <p class="mt-1 text-xs text-brand-mist">{{ $sp->directory }}</p>
                                    @if ($orgServersForCopy->isNotEmpty())
                                        <div class="mt-4 rounded-xl border border-brand-ink/10 bg-brand-sand/20 p-3 text-xs">
                                            <p class="font-medium text-brand-ink">{{ __('Copy to another server') }}</p>
                                            <div class="mt-2 flex flex-col gap-2 sm:flex-row sm:items-end">
                                                @if ($copy_source_program_id === $sp->id)
                                                    <select wire:model="copy_target_server_id" class="rounded-lg border border-brand-ink/15 bg-white px-2 py-1.5 text-xs">
                                                        <option value="">{{ __('Target server…') }}</option>
                                                        @foreach ($orgServersForCopy as $os)
                                                            <option value="{{ $os->id }}">{{ $os->name }}</option>
                                                        @endforeach
                                                    </select>
                                                    <input
                                                        type="text"
                                                        wire:model="copy_new_slug"
                                                        class="rounded-lg border border-brand-ink/15 bg-white px-2 py-1.5 font-mono text-xs"
                                                        placeholder="{{ __('new-slug') }}"
                                                    />
                                                    <button
                                                        type="button"
                                                        wire:click="copyProgramToServer"
                                                        class="rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-white"
                                                    >{{ __('Copy') }}</button>
                                                    <button type="button" wire:click="$set('copy_source_program_id', '')" class="text-brand-moss hover:underline">{{ __('Cancel') }}</button>
                                                @else
                                                    <button
                                                        type="button"
                                                        wire:click="$set('copy_source_program_id', '{{ $sp->id }}')"
                                                        class="text-brand-forest hover:underline"
                                                    >{{ __('Prepare copy…') }}</button>
                                                @endif
                                            </div>
                                        </div>
                                    @endif
                                </div>
                                <div class="flex shrink-0 flex-wrap items-center gap-1 border-t border-brand-ink/5 bg-brand-sand/10 px-2 py-3 sm:border-l sm:border-t-0 sm:px-3">
                                    <button
                                        type="button"
                                        wire:click="beginEditProgram('{{ $sp->id }}')"
                                        class="rounded-lg p-2 text-brand-ink hover:bg-white"
                                        title="{{ __('Edit') }}"
                                    >
                                        <x-heroicon-o-pencil-square class="h-5 w-5" />
                                    </button>
                                    <button
                                        type="button"
                                        wire:click="startOneProgram('{{ $sp->id }}')"
                                        wire:loading.attr="disabled"
                                        wire:target="startOneProgram"
                                        @disabled($supervisor_installed !== true)
                                        class="rounded-lg p-2 text-brand-forest hover:bg-emerald-50 disabled:opacity-40"
                                        title="{{ __('Start') }}"
                                    >
                                        <x-heroicon-o-play class="h-5 w-5" />
                                    </button>
                                    <button
                                        type="button"
                                        wire:click="stopOneProgram('{{ $sp->id }}')"
                                        wire:loading.attr="disabled"
                                        wire:target="stopOneProgram"
                                        @disabled($supervisor_installed !== true)
                                        class="rounded-lg p-2 text-amber-800 hover:bg-amber-50 disabled:opacity-40"
                                        title="{{ __('Stop') }}"
                                    >
                                        <x-heroicon-o-stop class="h-5 w-5" />
                                    </button>
                                    <button
                                        type="button"
                                        wire:click="restartOneProgram('{{ $sp->id }}')"
                                        wire:loading.attr="disabled"
                                        wire:target="restartOneProgram"
                                        @disabled($supervisor_installed !== true)
                                        class="rounded-lg p-2 text-brand-forest hover:bg-emerald-50 disabled:opacity-40"
                                        title="{{ __('Restart') }}"
                                    >
                                        <x-heroicon-o-arrow-path class="h-5 w-5" />
                                    </button>
                                    <button
                                        type="button"
                                        wire:click="openConfirmActionModal('deleteSupervisorProgram', ['{{ $sp->id }}'], @js(__('Delete program')), @js(__('Delete this program? Sync Supervisor afterward to remove its config from the server.')), @js(__('Delete program')), true)"
                                        class="rounded-lg p-2 text-red-600 hover:bg-red-50"
                                        title="{{ __('Delete') }}"
                                    >
                                        <x-heroicon-o-trash class="h-5 w-5" />
                                    </button>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <p class="border-t border-brand-ink/10 px-6 py-4 text-xs text-brand-moss sm:px-8">
                    {{ __('Removing a program deletes it from Dply and its conf file on the next sync. Orphan conf files are cleaned when you sync.') }}
                </p>
            </div>
        </div>
