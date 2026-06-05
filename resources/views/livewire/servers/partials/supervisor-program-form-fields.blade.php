        <div class="min-h-0 flex-1 space-y-6 overflow-y-auto px-6 py-6">
            @if ($editing_program_id)
                <div class="rounded-xl border border-amber-200 bg-amber-50/70 px-4 py-3 text-sm text-amber-950">
                    <p class="inline-flex items-center gap-1.5">
                        <x-heroicon-m-pencil-square class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Editing an existing program. Save to update Dply; sync to apply on the server.') }}
                    </p>
                </div>
            @endif

            @unless ($editing_program_id)
                <div>
                    <x-input-label for="supervisor_preset_picker" value="{{ __('Start from a preset (optional)') }}" />
                    <select
                        id="supervisor_preset_picker"
                        x-data
                        x-on:change="if ($event.target.value) { $wire.applySupervisorPreset($event.target.value); $event.target.value = ''; }"
                        class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                    >
                        <option value="">{{ __('— Pick a preset to fill the form —') }}</option>
                        @foreach ($supervisorPresets as $preset)
                            <option value="{{ $preset['value'] }}">{{ $preset['label'] }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-brand-moss">{{ __('Selecting a preset fills the form below — you can still tweak anything before saving.') }}</p>
                </div>
            @endunless

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

            @if ($lockSiteId ?? false)
                <input type="hidden" wire:model="new_sv_site_id" />
                <p class="text-sm text-brand-moss">{{ __('This worker is scoped to the current site.') }}</p>
            @else
                <div>
                    <x-input-label for="new_sv_site_id" value="{{ __('Related site (optional)') }}" />
                    <select
                        id="new_sv_site_id"
                        wire:model.live="new_sv_site_id"
                        class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                    >
                        <option value="">{{ __('None') }}</option>
                        @foreach ($sitesForServer as $st)
                            <option value="{{ $st->id }}">{{ $st->name }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-brand-moss">{{ __('Links this worker to a site for clarity and deploy restarts when enabled on the site.') }}</p>
                </div>
            @endif

            @if ($new_sv_type === 'queue' && ($supervisorFormSiteIsLaravel ?? false))
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

            <div @class(['hidden' => $new_sv_type === 'queue' && $queue_builder_mode === 'quick', 'space-y-5'])>
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

                <div>
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

                <div>
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
        </div>
