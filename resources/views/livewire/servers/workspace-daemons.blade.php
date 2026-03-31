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

        <div
            class="mb-6 flex flex-wrap gap-1 rounded-2xl border border-brand-ink/10 bg-brand-sand/20 p-1 shadow-sm"
            role="tablist"
            aria-label="{{ __('Daemons workspace sections') }}"
        >
            <button
                type="button"
                role="tab"
                id="daemons-tab-programs"
                wire:click="$set('daemons_workspace_tab', 'programs')"
                aria-selected="{{ $daemons_workspace_tab === 'programs' ? 'true' : 'false' }}"
                class="min-w-[7rem] flex-1 rounded-xl px-3 py-2.5 text-center text-sm font-medium transition-colors sm:flex-none {{ $daemons_workspace_tab === 'programs' ? 'bg-white text-brand-ink shadow-sm ring-1 ring-brand-ink/10' : 'text-brand-moss hover:bg-white/60 hover:text-brand-ink' }}"
            >
                {{ __('Programs') }}
            </button>
            <button
                type="button"
                role="tab"
                id="daemons-tab-preview"
                wire:click="$set('daemons_workspace_tab', 'preview')"
                aria-selected="{{ $daemons_workspace_tab === 'preview' ? 'true' : 'false' }}"
                class="min-w-[7rem] flex-1 rounded-xl px-3 py-2.5 text-center text-sm font-medium transition-colors sm:flex-none {{ $daemons_workspace_tab === 'preview' ? 'bg-white text-brand-ink shadow-sm ring-1 ring-brand-ink/10' : 'text-brand-moss hover:bg-white/60 hover:text-brand-ink' }}"
            >
                {{ __('Sync preview') }}
            </button>
            <button
                type="button"
                role="tab"
                id="daemons-tab-output"
                wire:click="$set('daemons_workspace_tab', 'output')"
                aria-selected="{{ $daemons_workspace_tab === 'output' ? 'true' : 'false' }}"
                class="min-w-[7rem] flex-1 rounded-xl px-3 py-2.5 text-center text-sm font-medium transition-colors sm:flex-none {{ $daemons_workspace_tab === 'output' ? 'bg-white text-brand-ink shadow-sm ring-1 ring-brand-ink/10' : 'text-brand-moss hover:bg-white/60 hover:text-brand-ink' }}"
            >
                {{ __('Sync output') }}
            </button>
            <button
                type="button"
                role="tab"
                id="daemons-tab-drift"
                wire:click="$set('daemons_workspace_tab', 'drift')"
                aria-selected="{{ $daemons_workspace_tab === 'drift' ? 'true' : 'false' }}"
                class="min-w-[7rem] flex-1 rounded-xl px-3 py-2.5 text-center text-sm font-medium transition-colors sm:flex-none {{ $daemons_workspace_tab === 'drift' ? 'bg-white text-brand-ink shadow-sm ring-1 ring-brand-ink/10' : 'text-brand-moss hover:bg-white/60 hover:text-brand-ink' }}"
            >
                {{ __('Drift') }}
            </button>
            <button
                type="button"
                role="tab"
                id="daemons-tab-logs"
                wire:click="$set('daemons_workspace_tab', 'logs')"
                aria-selected="{{ $daemons_workspace_tab === 'logs' ? 'true' : 'false' }}"
                class="min-w-[7rem] flex-1 rounded-xl px-3 py-2.5 text-center text-sm font-medium transition-colors sm:flex-none {{ $daemons_workspace_tab === 'logs' ? 'bg-white text-brand-ink shadow-sm ring-1 ring-brand-ink/10' : 'text-brand-moss hover:bg-white/60 hover:text-brand-ink' }}"
            >
                {{ __('Logs') }}
            </button>
            <button
                type="button"
                role="tab"
                id="daemons-tab-inspect"
                wire:click="$set('daemons_workspace_tab', 'inspect')"
                aria-selected="{{ $daemons_workspace_tab === 'inspect' ? 'true' : 'false' }}"
                class="min-w-[7rem] flex-1 rounded-xl px-3 py-2.5 text-center text-sm font-medium transition-colors sm:flex-none {{ $daemons_workspace_tab === 'inspect' ? 'bg-white text-brand-ink shadow-sm ring-1 ring-brand-ink/10' : 'text-brand-moss hover:bg-white/60 hover:text-brand-ink' }}"
            >
                {{ __('Inspect') }}
            </button>
        </div>

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
                            wire:click="applySupervisorPreset('nodejs')"
                            class="rounded-lg border border-brand-ink/10 bg-brand-sand/30 px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/50"
                        >{{ __('Preset: Node') }}</button>
                        <button
                            type="button"
                            wire:click="applySupervisorPreset('sidekiq')"
                            class="rounded-lg border border-brand-ink/10 bg-brand-sand/30 px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/50"
                        >{{ __('Preset: Sidekiq') }}</button>
                    </div>

                    <form id="daemon-program-form" wire:submit="addSupervisorProgram" class="mt-6 space-y-6">
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
                    </form>
                </div>
                <div class="flex flex-col-reverse items-stretch justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4 sm:flex-row sm:items-center sm:justify-end">
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
                        wire:click="syncSupervisor"
                        wire:loading.attr="disabled"
                        @disabled($supervisor_installed !== true)
                        class="inline-flex items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {{ __('Sync Supervisor on server') }}
                    </button>
                    <x-primary-button type="submit" form="daemon-program-form" class="justify-center">
                        {{ __('Add program') }}
                    </x-primary-button>
                </div>
            </div>

            <div class="{{ $card }}">
                <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                    <h2 class="text-xs font-semibold uppercase tracking-wider text-brand-mist">{{ __('Programs on this server') }}</h2>
                </div>
                @if ($server->supervisorPrograms->isEmpty())
                    <p class="px-6 py-10 text-center text-sm text-brand-moss sm:px-8">
                        {{ __('No programs yet. Add one above, then sync to write configs and reload Supervisor.') }}
                    </p>
                @else
                    <ul class="divide-y divide-brand-ink/10">
                        @foreach ($server->supervisorPrograms as $sp)
                            <li class="relative flex">
                                <span
                                    @class([
                                        'absolute bottom-0 left-0 top-0 w-1',
                                        'bg-brand-forest' => $sp->is_active,
                                        'bg-brand-mist' => ! $sp->is_active,
                                    ])
                                    aria-hidden="true"
                                ></span>
                                <div class="min-w-0 flex-1 py-4 pl-5 pr-4 sm:py-5 sm:pl-6 sm:pr-6">
                                    <p class="font-mono text-sm font-semibold text-brand-ink">{{ $sp->slug }}</p>
                                    <p class="mt-1 text-xs text-brand-moss">
                                        <span class="font-medium text-brand-ink/80">{{ $sp->program_type }}</span>
                                        · {{ __('user') }} {{ $sp->user }}
                                        · {{ __('numprocs') }} {{ $sp->numprocs }}
                                    </p>
                                    <p class="mt-2 break-all font-mono text-xs leading-relaxed text-brand-moss">{{ $sp->command }}</p>
                                    <p class="mt-1 text-xs text-brand-mist">{{ $sp->directory }}</p>
                                </div>
                                <div class="flex shrink-0 items-center gap-1 border-l border-brand-ink/5 bg-brand-sand/10 px-2 py-3 sm:px-3">
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
                                        wire:click="deleteSupervisorProgram('{{ $sp->id }}')"
                                        wire:confirm="{{ __('Delete this program? Sync Supervisor afterward to remove its config from the server.') }}"
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

        {{-- Sync preview (dry-run diff) --}}
        <div
            @class([
                'hidden' => $daemons_workspace_tab !== 'preview',
            ])
            role="tabpanel"
            id="daemons-panel-preview"
            aria-labelledby="daemons-tab-preview"
            aria-hidden="{{ $daemons_workspace_tab !== 'preview' ? 'true' : 'false' }}"
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

        {{-- Drift --}}
        <div
            @class([
                'hidden' => $daemons_workspace_tab !== 'drift',
            ])
            role="tabpanel"
            id="daemons-panel-drift"
            aria-labelledby="daemons-tab-drift"
            aria-hidden="{{ $daemons_workspace_tab !== 'drift' ? 'true' : 'false' }}"
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
                <div class="space-y-4 p-6 sm:p-8">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
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

        {{-- Sync output --}}
        <div
            @class([
                'hidden' => $daemons_workspace_tab !== 'output',
            ])
            role="tabpanel"
            id="daemons-panel-output"
            aria-labelledby="daemons-tab-output"
            aria-hidden="{{ $daemons_workspace_tab !== 'output' ? 'true' : 'false' }}"
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
                        {{ __('Read-only: runs supervisorctl status over SSH to show process state on the guest.') }}
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
        </div>
    @else
        <div class="rounded-2xl border border-brand-gold/40 bg-brand-sand/40 px-5 py-4 text-sm text-brand-olive">
            {{ __('Provisioning and SSH must be ready before you can use this section.') }}
        </div>
    @endif

    <x-slot name="modals">
        @include('livewire.servers.partials.remove-server-modal', [
            'open' => $showRemoveServerModal,
            'serverName' => $server->name,
            'serverId' => $server->id,
            'deletionSummary' => $deletionSummary,
        ])
    </x-slot>
</x-server-workspace-layout>
