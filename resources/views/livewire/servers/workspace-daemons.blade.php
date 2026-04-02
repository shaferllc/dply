@php
    $card = 'rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden';
    $opsReady = $server->isReady() && $server->ssh_private_key;
@endphp

<x-server-workspace-layout
    :server="$server"
    active="daemons"
    :title="__('Daemons')"
    :description="__('Supervisor is installed during server provisioning by default. If it is missing on this machine, install it here, then Dply can write configs under /etc/supervisor/conf.d and run supervisorctl reread/update.')"
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    @if ($opsReady)
        <div @if ($server->supervisor_package_status === null) wire:init="refreshSupervisorInstallStatus" @endif>
        @if ($supervisor_installed === null)
            <p class="mb-4 flex items-center gap-2 text-sm text-brand-moss">
                <x-spinner variant="forest" />
                {{ __('Checking Supervisor installation…') }}
            </p>
        @elseif ($supervisor_installed === false)
            <div class="mb-6 rounded-2xl border border-amber-300/80 bg-amber-50/90 px-5 py-4 sm:flex sm:flex-row sm:items-center sm:justify-between sm:gap-6">
                <div class="min-w-0">
                    <h2 class="text-sm font-semibold text-amber-950">{{ __('Supervisor is not installed') }}</h2>
                    <p class="mt-1 text-sm text-amber-900/90">{{ __('This server does not have the Supervisor package yet (skipped provision step, older server, or install failed). Install it here before syncing program configs.') }}</p>
                </div>
                <button
                    type="button"
                    wire:click="installSupervisorPackage"
                    wire:loading.attr="disabled"
                    class="mt-4 inline-flex shrink-0 items-center justify-center rounded-lg bg-amber-900 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-amber-950 disabled:opacity-50 sm:mt-0"
                >
                    <span wire:loading.remove wire:target="installSupervisorPackage">{{ __('Install Supervisor') }}</span>
                    <span wire:loading wire:target="installSupervisorPackage" class="inline-flex items-center gap-2">
                        <x-spinner variant="white" />
                        {{ __('Installing…') }}
                    </span>
                </button>
            </div>
        @endif

        <x-server-workspace-tablist :aria-label="__('Daemons workspace sections')">
            <x-server-workspace-tab id="daemons-tab-programs" :active="$daemons_workspace_tab === 'programs'" wire:click="$set('daemons_workspace_tab', 'programs')">
                {{ __('Programs') }}
            </x-server-workspace-tab>
            <x-server-workspace-tab id="daemons-tab-service" :active="$daemons_workspace_tab === 'service'" wire:click="$set('daemons_workspace_tab', 'service')">
                {{ __('Service') }}
            </x-server-workspace-tab>
            <x-server-workspace-tab id="daemons-tab-sync" :active="$daemons_workspace_tab === 'sync'" wire:click="$set('daemons_workspace_tab', 'sync')">
                {{ __('Sync') }}
            </x-server-workspace-tab>
            <x-server-workspace-tab id="daemons-tab-logs" :active="$daemons_workspace_tab === 'logs'" wire:click="$set('daemons_workspace_tab', 'logs')">
                {{ __('Logs') }}
            </x-server-workspace-tab>
            <x-server-workspace-tab id="daemons-tab-inspect" :active="$daemons_workspace_tab === 'inspect'" wire:click="$set('daemons_workspace_tab', 'inspect')">
                {{ __('Inspect') }}
            </x-server-workspace-tab>
            <x-server-workspace-tab id="daemons-tab-activity" :active="$daemons_workspace_tab === 'activity'" wire:click="$set('daemons_workspace_tab', 'activity')">
                {{ __('Activity') }}
            </x-server-workspace-tab>
        </x-server-workspace-tablist>

        {{-- Programs --}}
        <div
            @class([
                'space-y-8',
                'hidden' => $daemons_workspace_tab !== 'programs',
            ])
            role="tabpanel"
            id="daemons-panel-programs"
            aria-labelledby="daemons-tab-programs"
            aria-hidden="{{ $daemons_workspace_tab !== 'programs' ? 'true' : 'false' }}"
        >
            <div class="{{ $card }}">
                <div class="p-6 sm:p-8">
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('New Supervisor program') }}</h2>
                    <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                        {{ __('Each program becomes a conf file on the server. After saving here, use “Sync Supervisor on server” to write files and reload Supervisor.') }}
                    </p>

                    <div class="mt-6 flex flex-wrap gap-2">
                        <button
                            type="button"
                            wire:click="applySupervisorPreset('laravel-queue')"
                            class="rounded-lg border border-brand-ink/10 bg-brand-sand/30 px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/50"
                        >{{ __('Preset: queue worker') }}</button>
                        <button
                            type="button"
                            wire:click="applySupervisorPreset('laravel-horizon')"
                            class="rounded-lg border border-brand-ink/10 bg-brand-sand/30 px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/50"
                        >{{ __('Preset: Horizon') }}</button>
                        <button
                            type="button"
                            wire:click="applySupervisorPreset('reverb')"
                            class="rounded-lg border border-brand-ink/10 bg-brand-sand/30 px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/50"
                        >{{ __('Preset: Reverb') }}</button>
                        <button
                            type="button"
                            wire:click="applySupervisorPreset('laravel-schedule')"
                            class="rounded-lg border border-brand-ink/10 bg-brand-sand/30 px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/50"
                        >{{ __('Preset: schedule:work') }}</button>
                        <button
                            type="button"
                            wire:click="applySupervisorPreset('laravel-octane')"
                            class="rounded-lg border border-brand-ink/10 bg-brand-sand/30 px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/50"
                        >{{ __('Preset: Laravel Octane') }}</button>
                        <button
                            type="button"
                            wire:click="applySupervisorPreset('nodejs')"
                            class="rounded-lg border border-brand-ink/10 bg-brand-sand/30 px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/50"
                        >{{ __('Preset: Node') }}</button>
                        <button
                            type="button"
                            wire:click="applySupervisorPreset('sidekiq')"
                            class="rounded-lg border border-brand-ink/10 bg-brand-sand/30 px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/50"
                        >{{ __('Preset: Sidekiq') }}</button>
                    </div>

                    <form id="daemon-program-form" wire:submit="saveSupervisorProgram" class="mt-6 space-y-6">
                        @if ($editing_program_id)
                            <div class="rounded-xl border border-amber-300/80 bg-amber-50/80 px-4 py-3 text-sm text-amber-950">
                                {{ __('Editing an existing program. Save to update Dply; sync to apply on the server.') }}
                                <button type="button" wire:click="cancelEditProgram" class="ml-2 font-semibold underline">{{ __('Cancel') }}</button>
                            </div>
                        @endif
                        <div class="grid gap-6 sm:grid-cols-2">
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
                                <x-input-label for="new_sv_type" value="{{ __('Program type') }}" />
                                <select
                                    id="new_sv_type"
                                    wire:model="new_sv_type"
                                    class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                                >
                                    <option value="horizon">horizon</option>
                                    <option value="queue">queue</option>
                                    <option value="octane">octane</option>
                                    <option value="custom">custom</option>
                                </select>
                                <x-input-error :messages="$errors->get('new_sv_type')" class="mt-1" />
                            </div>
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
                                placeholder="/var/www/app/current"
                            />
                            <x-input-error :messages="$errors->get('new_sv_directory')" class="mt-1" />
                        </div>

                        <div class="grid gap-6 sm:grid-cols-2">
                            <div>
                                <x-input-label for="new_sv_user" value="{{ __('Run as user') }}" />
                                <input
                                    id="new_sv_user"
                                    type="text"
                                    wire:model="new_sv_user"
                                    class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 font-mono text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                                    placeholder="www-data"
                                />
                                <x-input-error :messages="$errors->get('new_sv_user')" class="mt-1" />
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
                        </div>

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
                            <x-input-error :messages="$errors->get('new_sv_stdout_logfile')" class="mt-1" />
                        </div>

                        <details class="rounded-xl border border-brand-ink/10 bg-brand-sand/20 px-4 py-3">
                            <summary class="cursor-pointer text-sm font-semibold text-brand-ink">{{ __('Expert Supervisor settings') }}</summary>
                            <div class="mt-4 grid gap-4 sm:grid-cols-2">
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
                        wire:click="restartAllPrograms"
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
                    <h2 class="text-xs font-semibold uppercase tracking-wider text-brand-mist">{{ __('Programs on this server') }}</h2>
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
                @else
                    <ul class="divide-y divide-brand-ink/10">
                        @foreach ($server->supervisorPrograms as $sp)
                            @php
                                $pst = $program_status_map[$sp->id]['state'] ?? 'unknown';
                                $badgeClass = match ($pst) {
                                    'running' => 'bg-emerald-100 text-emerald-900 ring-emerald-200',
                                    'starting' => 'bg-amber-100 text-amber-900 ring-amber-200',
                                    'stopped' => 'bg-zinc-100 text-zinc-700 ring-zinc-200',
                                    'fatal', 'backoff', 'exited' => 'bg-red-100 text-red-800 ring-red-200',
                                    default => 'bg-brand-sand text-brand-moss ring-brand-ink/10',
                                };
                            @endphp
                            <li class="relative flex flex-col sm:flex-row">
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

        {{-- Supervisor systemd service --}}
        <div
            @class([
                'hidden' => $daemons_workspace_tab !== 'service',
            ])
            role="tabpanel"
            id="daemons-panel-service"
            aria-labelledby="daemons-tab-service"
            aria-hidden="{{ $daemons_workspace_tab !== 'service' ? 'true' : 'false' }}"
        >
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
        </div>

        {{-- Sync: preview, drift, last output --}}
        <div
            @class([
                'space-y-4',
                'hidden' => $daemons_workspace_tab !== 'sync',
            ])
            role="tabpanel"
            id="daemons-panel-sync"
            aria-labelledby="daemons-tab-sync"
            aria-hidden="{{ $daemons_workspace_tab !== 'sync' ? 'true' : 'false' }}"
        >
            <div
                class="flex flex-wrap gap-1 rounded-2xl border border-brand-ink/10 bg-brand-sand/20 p-1 shadow-sm"
                role="tablist"
                aria-label="{{ __('Sync sections') }}"
            >
                <button
                    type="button"
                    role="tab"
                    id="daemons-sync-sub-preview"
                    wire:click="$set('daemons_sync_subtab', 'preview')"
                    aria-selected="{{ $daemons_sync_subtab === 'preview' ? 'true' : 'false' }}"
                    class="shrink-0 rounded-xl px-3 py-2 text-xs font-medium transition-colors sm:text-sm {{ $daemons_sync_subtab === 'preview' ? 'bg-white text-brand-ink shadow-sm ring-1 ring-brand-ink/10' : 'text-brand-moss hover:bg-white/60 hover:text-brand-ink' }}"
                >
                    {{ __('Preview') }}
                </button>
                <button
                    type="button"
                    role="tab"
                    id="daemons-sync-sub-drift"
                    wire:click="$set('daemons_sync_subtab', 'drift')"
                    aria-selected="{{ $daemons_sync_subtab === 'drift' ? 'true' : 'false' }}"
                    class="shrink-0 rounded-xl px-3 py-2 text-xs font-medium transition-colors sm:text-sm {{ $daemons_sync_subtab === 'drift' ? 'bg-white text-brand-ink shadow-sm ring-1 ring-brand-ink/10' : 'text-brand-moss hover:bg-white/60 hover:text-brand-ink' }}"
                >
                    {{ __('Drift') }}
                </button>
                <button
                    type="button"
                    role="tab"
                    id="daemons-sync-sub-output"
                    wire:click="$set('daemons_sync_subtab', 'output')"
                    aria-selected="{{ $daemons_sync_subtab === 'output' ? 'true' : 'false' }}"
                    class="shrink-0 rounded-xl px-3 py-2 text-xs font-medium transition-colors sm:text-sm {{ $daemons_sync_subtab === 'output' ? 'bg-white text-brand-ink shadow-sm ring-1 ring-brand-ink/10' : 'text-brand-moss hover:bg-white/60 hover:text-brand-ink' }}"
                >
                    {{ __('Last output') }}
                </button>
            </div>

            <div
                @class([
                    'hidden' => $daemons_sync_subtab !== 'preview',
                ])
                role="tabpanel"
                id="daemons-sync-panel-preview"
                aria-labelledby="daemons-sync-sub-preview"
            >
                <div class="{{ $card }}">
                    <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                        <h2 class="text-sm font-semibold text-brand-ink">{{ __('Sync preview') }}</h2>
                        <p class="mt-1 text-xs text-brand-moss leading-relaxed">
                            {{ __('Compare generated configs to files on the server before writing. Read-only over SSH.') }}
                        </p>
                    </div>
                    <div class="space-y-4 p-6 sm:p-8">
                        <button
                            type="button"
                            wire:click="loadPreviewSync"
                            wire:loading.attr="disabled"
                            wire:target="loadPreviewSync"
                            @disabled($supervisor_installed !== true)
                            class="inline-flex items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <span wire:loading.remove wire:target="loadPreviewSync">{{ __('Load preview') }}</span>
                            <span wire:loading wire:target="loadPreviewSync" class="inline-flex items-center gap-2">
                                <x-spinner variant="forest" />
                                {{ __('Loading…') }}
                            </span>
                        </button>
                        <pre class="max-h-[min(55vh,28rem)] overflow-auto whitespace-pre-wrap break-all rounded-xl bg-zinc-950 px-4 py-3 font-mono text-xs leading-relaxed text-zinc-100 [scrollbar-color:rgb(82_82_91/0.45)_transparent]">{{ $preview_sync_output !== '' ? $preview_sync_output : __('Click “Load preview”.') }}</pre>
                    </div>
                </div>
            </div>

            <div
                @class([
                    'hidden' => $daemons_sync_subtab !== 'drift',
                ])
                role="tabpanel"
                id="daemons-sync-panel-drift"
                aria-labelledby="daemons-sync-sub-drift"
            >
                <div class="{{ $card }}">
                    <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                        <h2 class="text-sm font-semibold text-brand-ink">{{ __('Config drift') }}</h2>
                        <p class="mt-1 text-xs text-brand-moss leading-relaxed">
                            {{ __('Compare Dply program IDs to dply-sv-*.conf files on the server.') }}
                        </p>
                    </div>
                    <div class="space-y-4 p-6 sm:p-8">
                        <button
                            type="button"
                            wire:click="loadDrift"
                            wire:loading.attr="disabled"
                            wire:target="loadDrift"
                            @disabled($supervisor_installed !== true)
                            class="inline-flex items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <span wire:loading.remove wire:target="loadDrift">{{ __('Check drift') }}</span>
                            <span wire:loading wire:target="loadDrift" class="inline-flex items-center gap-2">
                                <x-spinner variant="forest" />
                                {{ __('Loading…') }}
                            </span>
                        </button>
                        <pre class="max-h-[min(55vh,28rem)] overflow-auto whitespace-pre-wrap break-all rounded-xl bg-zinc-950 px-4 py-3 font-mono text-xs leading-relaxed text-zinc-100 [scrollbar-color:rgb(82_82_91/0.45)_transparent]">{{ $drift_output !== '' ? $drift_output : __('Click “Check drift”.') }}</pre>
                    </div>
                </div>
            </div>

            <div
                @class([
                    'hidden' => $daemons_sync_subtab !== 'output',
                ])
                role="tabpanel"
                id="daemons-sync-panel-output"
                aria-labelledby="daemons-sync-sub-output"
            >
                <div class="{{ $card }}">
                    <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                        <h2 class="text-sm font-semibold text-brand-ink">{{ __('Last sync log') }}</h2>
                        <p class="mt-1 text-xs text-brand-moss">
                            {{ __('Output from the most recent “Sync Supervisor on server” run. Run sync from the Programs tab to refresh.') }}
                        </p>
                    </div>
                    <div class="p-6 sm:p-8">
                        <pre class="max-h-[min(55vh,28rem)] overflow-auto whitespace-pre-wrap break-all rounded-xl bg-zinc-950 px-4 py-3 font-mono text-xs leading-relaxed text-zinc-100 [scrollbar-color:rgb(82_82_91/0.45)_transparent]">{{ $last_supervisor_sync_output !== '' ? $last_supervisor_sync_output : __('No sync yet. Use Programs → “Sync Supervisor on server”.') }}</pre>
                    </div>
                </div>
            </div>
        </div>

        {{-- Logs --}}
        <div
            @class([
                'hidden' => $daemons_workspace_tab !== 'logs',
            ])
            role="tabpanel"
            id="daemons-panel-logs"
            aria-labelledby="daemons-tab-logs"
            aria-hidden="{{ $daemons_workspace_tab !== 'logs' ? 'true' : 'false' }}"
        >
            <div class="{{ $card }}">
                <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                    <h2 class="text-sm font-semibold text-brand-ink">{{ __('Program logs') }}</h2>
                    <p class="mt-1 text-xs text-brand-moss leading-relaxed">
                        {{ __('Last lines from each program’s stdout log path (default under /tmp).') }}
                    </p>
                </div>
                <div
                    class="space-y-4 p-6 sm:p-8"
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
        </div>

        {{-- Inspect --}}
        <div
            @class([
                'hidden' => $daemons_workspace_tab !== 'inspect',
            ])
            role="tabpanel"
            id="daemons-panel-inspect"
            aria-labelledby="daemons-tab-inspect"
            aria-hidden="{{ $daemons_workspace_tab !== 'inspect' ? 'true' : 'false' }}"
        >
            <div class="{{ $card }}">
                <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                    <h2 class="text-sm font-semibold text-brand-ink">{{ __('Supervisor on the server') }}</h2>
                    <p class="mt-1 text-xs text-brand-moss leading-relaxed">
                        {{ __('Read-only: runs supervisorctl status over SSH. When the login user is not root, Dply uses sudo -n supervisorctl (passwordless sudo must be allowed for that user, same as provisioning).') }}
                    </p>
                </div>
                <div class="space-y-4 p-6 sm:p-8">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-sm text-brand-moss">{{ __('Loads live status from the server (same SSH user as deploys).') }}</p>
                        <button
                            type="button"
                            wire:click="loadSupervisorInspect"
                            wire:loading.attr="disabled"
                            @disabled($supervisor_installed !== true)
                            class="inline-flex shrink-0 items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <span wire:loading.remove wire:target="loadSupervisorInspect">{{ __('Load status') }}</span>
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
        </div>

        {{-- Activity --}}
        <div
            @class([
                'hidden' => $daemons_workspace_tab !== 'activity',
            ])
            role="tabpanel"
            id="daemons-panel-activity"
            aria-labelledby="daemons-tab-activity"
            aria-hidden="{{ $daemons_workspace_tab !== 'activity' ? 'true' : 'false' }}"
        >
            <div class="{{ $card }}">
                <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                    <h2 class="text-sm font-semibold text-brand-ink">{{ __('Audit log') }}</h2>
                    <p class="mt-1 text-xs text-brand-moss leading-relaxed">
                        {{ __('Recent daemon-related actions on this server (program changes, sync, restarts, copies).') }}
                    </p>
                </div>
                <div class="divide-y divide-brand-ink/10">
                    @forelse ($auditLogs as $log)
                        <div class="px-6 py-4 sm:px-8">
                            <p class="text-xs text-brand-mist">{{ $log->created_at->timezone(config('app.timezone'))->format('Y-m-d H:i:s') }}
                                @if ($log->user)
                                    · {{ $log->user->name }}
                                @endif
                            </p>
                            <p class="mt-1 font-mono text-sm text-brand-ink">{{ $log->action }}</p>
                            @if ($log->properties)
                                <pre class="mt-2 max-h-32 overflow-auto rounded-lg bg-zinc-950 p-3 font-mono text-[11px] text-zinc-300">{{ json_encode($log->properties, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                            @endif
                        </div>
                    @empty
                        <p class="px-6 py-10 text-center text-sm text-brand-moss sm:px-8">{{ __('No activity recorded yet.') }}</p>
                    @endforelse
                </div>
            </div>
        </div>
        </div>
    @else
        @include('livewire.servers.partials.workspace-ops-not-ready')
    @endif

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
        @include('livewire.servers.partials.remove-server-modal', [
            'open' => $showRemoveServerModal,
            'serverName' => $server->name,
            'serverId' => $server->id,
            'deletionSummary' => $deletionSummary,
        ])
    </x-slot>
</x-server-workspace-layout>
