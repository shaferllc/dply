@php
    $card = 'rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden';
    $opsReady = $server->isReady() && $server->ssh_private_key;
    $presets = [
        'every_minute' => [__('Every minute'), '* * * * *'],
        'hourly' => [__('Hourly'), '0 * * * *'],
        'nightly' => [__('Nightly (2:00)'), '0 2 * * *'],
        'weekly' => [__('Weekly (Sun 2:00)'), '0 2 * * 0'],
        'monthly' => [__('Monthly (1st 2:00)'), '0 2 1 * *'],
        'custom' => [__('Custom'), ''],
    ];
@endphp

<x-server-workspace-layout
    :server="$server"
    active="cron"
    :title="__('Cron jobs')"
    :description="__('Schedule commands in the Dply-managed crontab block for this server.')"
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    @if ($opsReady && $server->organization?->cron_maintenance_until && now()->lt($server->organization->cron_maintenance_until))
        <div class="mb-4 rounded-2xl border border-amber-400/90 bg-amber-50 px-5 py-4 text-sm text-amber-950">
            <p class="font-semibold">{{ __('Cron maintenance window active') }}</p>
            <p class="mt-1 text-amber-900/90">
                {{ __('Managed cron lines are not installed on servers until :time.', ['time' => $server->organization->cron_maintenance_until->timezone(config('app.timezone'))->format('Y-m-d H:i T')]) }}
                @if (filled($server->organization->cron_maintenance_note))
                    {{ $server->organization->cron_maintenance_note }}
                @endif
            </p>
        </div>
    @endif

    @if ($opsReady)
        <div
            id="dply-server-cron-run-context"
            class="hidden"
            aria-hidden="true"
            data-server-id="{{ $server->id }}"
            data-subscribe="{{ $cronRunEchoSubscribable ? '1' : '0' }}"
        ></div>

        <div
            class="mb-6 flex flex-wrap gap-1 rounded-2xl border border-brand-ink/10 bg-brand-sand/20 p-1 shadow-sm"
            role="tablist"
            aria-label="{{ __('Cron workspace sections') }}"
        >
            <button
                type="button"
                role="tab"
                id="cron-tab-jobs"
                wire:click="$set('cron_workspace_tab', 'jobs')"
                aria-selected="{{ $cron_workspace_tab === 'jobs' ? 'true' : 'false' }}"
                class="min-w-[7rem] flex-1 rounded-xl px-3 py-2.5 text-center text-sm font-medium transition-colors sm:flex-none {{ $cron_workspace_tab === 'jobs' ? 'bg-white text-brand-ink shadow-sm ring-1 ring-brand-ink/10' : 'text-brand-moss hover:bg-white/60 hover:text-brand-ink' }}"
            >
                {{ __('Jobs') }}
            </button>
            <button
                type="button"
                role="tab"
                id="cron-tab-run"
                wire:click="$set('cron_workspace_tab', 'run')"
                aria-selected="{{ $cron_workspace_tab === 'run' ? 'true' : 'false' }}"
                class="min-w-[7rem] flex-1 rounded-xl px-3 py-2.5 text-center text-sm font-medium transition-colors sm:flex-none {{ $cron_workspace_tab === 'run' ? 'bg-white text-brand-ink shadow-sm ring-1 ring-brand-ink/10' : 'text-brand-moss hover:bg-white/60 hover:text-brand-ink' }}"
            >
                {{ __('Run now') }}
            </button>
            <button
                type="button"
                role="tab"
                id="cron-tab-history"
                wire:click="$set('cron_workspace_tab', 'history')"
                aria-selected="{{ $cron_workspace_tab === 'history' ? 'true' : 'false' }}"
                class="min-w-[7rem] flex-1 rounded-xl px-3 py-2.5 text-center text-sm font-medium transition-colors sm:flex-none {{ $cron_workspace_tab === 'history' ? 'bg-white text-brand-ink shadow-sm ring-1 ring-brand-ink/10' : 'text-brand-moss hover:bg-white/60 hover:text-brand-ink' }}"
            >
                {{ __('History') }}
            </button>
            <button
                type="button"
                role="tab"
                id="cron-tab-templates"
                wire:click="$set('cron_workspace_tab', 'templates')"
                aria-selected="{{ $cron_workspace_tab === 'templates' ? 'true' : 'false' }}"
                class="min-w-[7rem] flex-1 rounded-xl px-3 py-2.5 text-center text-sm font-medium transition-colors sm:flex-none {{ $cron_workspace_tab === 'templates' ? 'bg-white text-brand-ink shadow-sm ring-1 ring-brand-ink/10' : 'text-brand-moss hover:bg-white/60 hover:text-brand-ink' }}"
            >
                {{ __('Templates') }}
            </button>
            <button
                type="button"
                role="tab"
                id="cron-tab-inspect"
                wire:click="$set('cron_workspace_tab', 'inspect')"
                aria-selected="{{ $cron_workspace_tab === 'inspect' ? 'true' : 'false' }}"
                class="min-w-[7rem] flex-1 rounded-xl px-3 py-2.5 text-center text-sm font-medium transition-colors sm:flex-none {{ $cron_workspace_tab === 'inspect' ? 'bg-white text-brand-ink shadow-sm ring-1 ring-brand-ink/10' : 'text-brand-moss hover:bg-white/60 hover:text-brand-ink' }}"
            >
                {{ __('Inspect') }}
            </button>
        </div>

        <div
            @class([
                'space-y-8',
                'hidden' => $cron_workspace_tab !== 'jobs',
            ])
            role="tabpanel"
            id="cron-panel-jobs"
            aria-labelledby="cron-tab-jobs"
            aria-hidden="{{ $cron_workspace_tab !== 'jobs' ? 'true' : 'false' }}"
        >
        <div class="{{ $card }}">
            <div class="p-6 sm:p-8">
                <h2 class="text-lg font-semibold text-brand-ink">
                    @if ($editing_job_id)
                        {{ __('Edit cron job') }}
                    @else
                        {{ __('New cron job') }}
                    @endif
                </h2>
                <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                    {{ __('Entries are written into the SSH user’s crontab inside a marked block. If the run-as user differs from that account, the line uses sudo (passwordless sudo may be required).') }}
                </p>

                <form id="cron-job-form" wire:submit="saveCronJob" class="mt-6 space-y-6">
                    <div>
                        <x-input-label for="command_preset" value="{{ __('Template') }}" />
                        <select
                            id="command_preset"
                            wire:model.live="command_preset"
                            class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                        >
                            <option value="custom">{{ __('Custom — type your own command') }}</option>
                            @foreach ($commandInstallPresets as $presetKey => $preset)
                                <option value="{{ $presetKey }}">{{ $preset['label'] }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1.5 text-xs text-brand-moss">
                            {{ __('Fills the fields below with typical commands—edit paths, domains, and users before saving.') }}
                        </p>
                    </div>

                    <div>
                        <x-input-label for="new_cron_command" value="{{ __('Command') }}" />
                        <x-text-input
                            id="new_cron_command"
                            wire:model="new_cron_command"
                            class="mt-1 block w-full font-mono text-sm"
                            placeholder="{{ __('e.g. php /home/deploy/app/artisan schedule:run') }}"
                        />
                        <p class="mt-1.5 text-xs text-brand-moss">
                            {{ __('Use an explicit PHP binary if needed (for example') }}
                            <span class="font-mono text-brand-ink/80">php8.2</span>).
                        </p>
                        <x-input-error :messages="$errors->get('new_cron_command')" class="mt-1" />
                    </div>

                    <div>
                        <div class="flex flex-wrap items-end justify-between gap-2">
                            <x-input-label for="new_cron_user" value="{{ __('Run as user') }}" class="min-w-0 flex-1" />
                            <button
                                type="button"
                                wire:click="refreshRunAsUserChoices"
                                wire:loading.attr="disabled"
                                class="shrink-0 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-50"
                            >
                                <span wire:loading.remove wire:target="refreshRunAsUserChoices">{{ __('Refresh list') }}</span>
                                <span wire:loading wire:target="refreshRunAsUserChoices" class="inline-flex items-center gap-1.5">
                                    <x-spinner variant="forest" size="sm" />
                                    {{ __('Refreshing…') }}
                                </span>
                            </button>
                        </div>
                        <input
                            id="new_cron_user"
                            type="text"
                            wire:model="new_cron_user"
                            list="cron-run-as-user-suggestions"
                            spellcheck="false"
                            autocorrect="off"
                            autocomplete="off"
                            class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 font-mono text-sm text-brand-ink shadow-sm placeholder:text-brand-mist focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                            placeholder="{{ $server->ssh_user }}"
                        />
                        <datalist id="cron-run-as-user-suggestions">
                            @foreach ($runAsUserDatalistChoices as $u)
                                <option value="{{ $u }}"></option>
                            @endforeach
                        </datalist>
                        <p class="mt-1.5 text-xs text-brand-moss">
                            {{ __('Pick from the list or type any user. Names come from /etc/passwd on the server (cached a few minutes).') }}
                        </p>
                        <x-input-error :messages="$errors->get('new_cron_user')" class="mt-1" />
                    </div>

                    <fieldset>
                        <legend class="text-sm font-medium text-brand-ink">
                            {{ __('Frequency') }}
                            <span class="font-normal text-brand-moss">({{ trim($new_cron_expression) }})</span>
                        </legend>
                        <div class="mt-3 grid gap-2 sm:grid-cols-2">
                            @foreach ($presets as $key => [$label, $expr])
                                <label class="flex cursor-pointer items-start gap-2 rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-3 py-2.5 has-[:checked]:border-brand-sage has-[:checked]:bg-brand-sage/10">
                                    <input
                                        type="radio"
                                        wire:model.live="frequency_preset"
                                        value="{{ $key }}"
                                        class="mt-0.5 rounded-full border-brand-mist text-brand-ink focus:ring-brand-sage"
                                    />
                                    <span class="text-sm text-brand-ink">
                                        {{ $label }}
                                        @if ($key !== 'custom' && $expr !== '')
                                            <span class="block font-mono text-xs text-brand-moss">({{ $expr }})</span>
                                        @endif
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        @if ($frequency_preset === 'custom')
                            <div class="mt-4">
                                <x-input-label for="new_cron_expression" value="{{ __('Cron expression') }}" />
                                <x-text-input
                                    id="new_cron_expression"
                                    wire:model.blur="new_cron_expression"
                                    class="mt-1 block w-full font-mono text-sm"
                                    placeholder="*/5 * * * *"
                                />
                                <x-input-error :messages="$errors->get('new_cron_expression')" class="mt-1" />
                            </div>
                        @endif
                    </fieldset>

                    <div>
                        <x-input-label for="new_description" value="{{ __('Description (optional)') }}" />
                        <textarea
                            id="new_description"
                            wire:model="new_description"
                            rows="2"
                            class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm placeholder:text-brand-mist focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                            placeholder="{{ __('e.g. Laravel scheduler') }}"
                        ></textarea>
                        <x-input-error :messages="$errors->get('new_description')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label for="new_site_id" value="{{ __('Attach to site (optional)') }}" />
                        <select
                            id="new_site_id"
                            wire:model="new_site_id"
                            class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                        >
                            <option value="">{{ __('None') }}</option>
                            @foreach ($server->sites as $s)
                                <option value="{{ $s->id }}">{{ $s->name }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('new_site_id')" class="mt-1" />
                    </div>

                    <details class="mt-6 rounded-xl border border-brand-ink/10 bg-brand-sand/15 open:shadow-sm">
                        <summary class="cursor-pointer select-none px-4 py-3 text-sm font-medium text-brand-ink">{{ __('Advanced: timezone, locking, alerts, env, dependency') }}</summary>
                        <div class="space-y-4 border-t border-brand-ink/10 px-4 py-4">
                            <div>
                                <x-input-label for="new_schedule_timezone" value="{{ __('Schedule timezone (export TZ before command)') }}" />
                                <x-text-input id="new_schedule_timezone" wire:model="new_schedule_timezone" class="mt-1 block w-full font-mono text-sm" placeholder="UTC" />
                                <p class="mt-1 text-xs text-brand-moss">{{ __('Crontab fires on the server clock; this prepends export TZ for the shell (Run now and synced lines).') }}</p>
                            </div>
                            <div>
                                <x-input-label for="new_overlap_policy" value="{{ __('Overlap') }}" />
                                <select id="new_overlap_policy" wire:model="new_overlap_policy" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm">
                                    <option value="allow">{{ __('Allow overlapping runs') }}</option>
                                    <option value="skip_if_running">{{ __('Skip if still running (flock)') }}</option>
                                </select>
                            </div>
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                                <label class="inline-flex items-center gap-2 text-sm text-brand-ink">
                                    <input type="checkbox" wire:model="new_alert_on_failure" class="rounded border-brand-mist text-brand-forest" />
                                    {{ __('Alert org owners/admins on non-zero exit') }}
                                </label>
                                <label class="inline-flex items-center gap-2 text-sm text-brand-ink">
                                    <input type="checkbox" wire:model="new_alert_on_pattern_match" class="rounded border-brand-mist text-brand-forest" />
                                    {{ __('Alert when output matches regex') }}
                                </label>
                            </div>
                            <div>
                                <x-input-label for="new_alert_pattern" value="{{ __('Alert PCRE pattern (e.g. /error/i)') }}" />
                                <x-text-input id="new_alert_pattern" wire:model="new_alert_pattern" class="mt-1 block w-full font-mono text-sm" />
                            </div>
                            <div>
                                <x-input-label for="new_env_prefix" value="{{ __('Env / exports before command (optional)') }}" />
                                <textarea id="new_env_prefix" wire:model="new_env_prefix" rows="3" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink" placeholder="export MY_TOKEN=…"></textarea>
                            </div>
                            <div>
                                <x-input-label for="new_depends_on_job_id" value="{{ __('Run after job (manual “Run now” only)') }}" />
                                <select id="new_depends_on_job_id" wire:model="new_depends_on_job_id" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm">
                                    <option value="">{{ __('None') }}</option>
                                    @foreach ($dependsJobChoices as $dj)
                                        <option value="{{ $dj->id }}">{{ $dj->description ?: Str::limit($dj->command, 48) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <x-input-label for="new_maintenance_tag" value="{{ __('Maintenance tag (optional, for your notes)') }}" />
                                <x-text-input id="new_maintenance_tag" wire:model="new_maintenance_tag" class="mt-1 block w-full text-sm" />
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <button type="button" wire:click="validateCronExpressionField" class="rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                    {{ __('Validate expression') }}
                                </button>
                                <button type="button" wire:click="dryRunFormCommand" class="rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                    {{ __('Dry run (preview shell)') }}
                                </button>
                            </div>
                        </div>
                    </details>
                </form>
            </div>
            <div class="flex flex-col-reverse items-stretch justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4 sm:flex-row sm:items-center sm:justify-end">
                @if ($editing_job_id)
                    <button
                        type="button"
                        wire:click="cancelEdit"
                        class="inline-flex items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40"
                    >
                        {{ __('Cancel') }}
                    </button>
                @endif
                <button
                    type="button"
                    wire:click="syncCronJobs"
                    class="inline-flex items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40"
                >
                    {{ __('Sync crontab on server') }}
                </button>
                <x-primary-button type="submit" form="cron-job-form" class="justify-center">
                    @if ($editing_job_id)
                        {{ __('Save changes') }}
                    @else
                        {{ __('Add cron job') }}
                    @endif
                </x-primary-button>
            </div>
        </div>

        <div class="{{ $card }}">
            <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                <h2 class="text-xs font-semibold uppercase tracking-wider text-brand-mist">{{ __('Cron jobs on this server') }}</h2>
                <div class="mt-3">
                    <x-input-label for="cron_job_search" value="{{ __('Search jobs') }}" class="sr-only" />
                    <input
                        id="cron_job_search"
                        type="search"
                        wire:model.live.debounce.300ms="cron_job_search"
                        class="block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm placeholder:text-brand-mist"
                        placeholder="{{ __('Filter by command or description…') }}"
                    />
                </div>
            </div>
            @if ($filteredCronJobs->isEmpty())
                <p class="px-6 py-10 text-center text-sm text-brand-moss sm:px-8">
                    {{ $server->cronJobs->isEmpty()
                        ? __('No custom jobs yet. Add one in the form on this tab, then sync the crontab to install the Dply-managed block.')
                        : __('No jobs match your search.') }}
                </p>
            @else
                <ul class="divide-y divide-brand-ink/10">
                    @foreach ($filteredCronJobs as $cj)
                        @php
                            $siteLabel = $cj->site?->name;
                            $primaryDomain = $cj->site?->domains?->sortByDesc('is_primary')->first();
                            if ($siteLabel && $primaryDomain?->hostname) {
                                $siteLabel = $primaryDomain->hostname;
                            }
                        @endphp
                        <li class="relative flex">
                            <span
                                @class([
                                    'absolute bottom-0 left-0 top-0 w-1',
                                    'bg-brand-forest' => $cj->enabled,
                                    'bg-brand-mist' => ! $cj->enabled,
                                ])
                                aria-hidden="true"
                            ></span>
                            <div class="min-w-0 flex-1 py-4 pl-5 pr-4 sm:py-5 sm:pl-6 sm:pr-6">
                                @if (filled($cj->description))
                                    <p class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ $cj->description }}</p>
                                @endif
                                <p class="mt-1 break-all font-mono text-sm font-semibold text-brand-ink">{{ $cj->command }}</p>
                                <p class="mt-2 text-xs text-brand-moss">
                                    <span class="font-mono text-brand-ink/90">{{ $cj->cron_expression }}</span>
                                    {{ __('via user :user', ['user' => $cj->user]) }}
                                    @if ($siteLabel)
                                        <span class="text-brand-mist"> · </span>{{ __('attached to :host', ['host' => $siteLabel]) }}
                                    @endif
                                    @if ($cj->depends_on_job_id && $cj->dependsOn)
                                        <span class="text-brand-mist"> · </span>{{ __('after :d', ['d' => $cj->dependsOn->description ?: \Illuminate\Support\Str::limit($cj->dependsOn->command, 32)]) }}
                                    @endif
                                    @if (! $cj->enabled)
                                        <span class="ml-1 rounded bg-brand-mist/30 px-1.5 py-0.5 text-[0.65rem] font-semibold uppercase text-brand-ink/70">{{ __('Paused') }}</span>
                                    @endif
                                </p>
                                @if ($cj->last_sync_error && ! $cj->is_synced)
                                    <p class="mt-1 text-xs text-red-600">{{ __('Last sync issue — try syncing again.') }}</p>
                                @endif
                            </div>
                            <div class="flex shrink-0 items-center gap-1 border-l border-brand-ink/5 bg-brand-sand/10 px-2 py-3 sm:flex-col sm:justify-center sm:px-3">
                                <button
                                    type="button"
                                    wire:click="startEdit('{{ $cj->id }}')"
                                    class="rounded-lg p-2 text-brand-ink hover:bg-brand-sand/60"
                                    title="{{ __('Edit') }}"
                                >
                                    <x-heroicon-o-pencil-square class="h-5 w-5" />
                                </button>
                                <button
                                    type="button"
                                    wire:click="toggleCronJob('{{ $cj->id }}')"
                                    class="rounded-lg p-2 text-brand-ink hover:bg-brand-sand/60"
                                    title="{{ $cj->enabled ? __('Pause') : __('Resume') }}"
                                >
                                    @if ($cj->enabled)
                                        <x-heroicon-o-pause class="h-5 w-5" />
                                    @else
                                        <x-heroicon-o-play class="h-5 w-5" />
                                    @endif
                                </button>
                                <button
                                    type="button"
                                    wire:click="runCronJobNow('{{ $cj->id }}')"
                                    class="rounded-lg p-2 text-brand-ink hover:bg-brand-sand/60 disabled:opacity-40"
                                    title="{{ __('Run now') }}"
                                    @disabled(! $cj->enabled)
                                >
                                    <x-heroicon-o-bolt class="h-5 w-5" />
                                </button>
                                <button
                                    type="button"
                                    wire:click="openLogsModal('{{ $cj->id }}')"
                                    class="rounded-lg p-2 text-brand-ink hover:bg-brand-sand/60"
                                    title="{{ __('Last run output') }}"
                                >
                                    <x-heroicon-o-document-text class="h-5 w-5" />
                                </button>
                                <button
                                    type="button"
                                    wire:click="deleteCronJob('{{ $cj->id }}')"
                                    wire:confirm="{{ __('Delete this cron job? Sync the crontab afterward to remove it from the server.') }}"
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
                {{ __('Sync also adds Laravel scheduler lines for sites with that enabled. The document icon is the last saved run output; live output streams on the Run now tab.') }}
            </p>
        </div>
        </div>

        <div
            @class([
                'hidden' => $cron_workspace_tab !== 'run',
            ])
            role="tabpanel"
            id="cron-panel-run"
            aria-labelledby="cron-tab-run"
            aria-hidden="{{ $cron_workspace_tab !== 'run' ? 'true' : 'false' }}"
        >
            <div class="space-y-8">
                <div class="{{ $card }}">
                    <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                        <h2 class="text-sm font-semibold text-brand-ink">{{ __('Choose a job to run') }}</h2>
                        <p class="mt-1 text-xs text-brand-moss">
                            {{ __('Queues a one-off run over SSH (same wrapping as in crontab). Ensure queue workers are running.') }}
                        </p>
                    </div>
                    @if ($server->cronJobs->isEmpty())
                        <p class="px-6 py-10 text-center text-sm text-brand-moss sm:px-8">
                            {{ __('No cron jobs yet. Add one on the Jobs tab, then come back here to run it.') }}
                        </p>
                    @else
                        <ul class="divide-y divide-brand-ink/10">
                            @foreach ($server->cronJobs as $cj)
                                <li class="flex flex-col gap-3 px-6 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-8">
                                    <div class="min-w-0 flex-1">
                                        @if (filled($cj->description))
                                            <p class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ $cj->description }}</p>
                                        @endif
                                        <p class="mt-0.5 break-all font-mono text-sm text-brand-ink">{{ \Illuminate\Support\Str::limit($cj->command, 140) }}</p>
                                        <p class="mt-1 text-xs text-brand-moss">
                                            <span class="font-mono text-brand-ink/80">{{ $cj->cron_expression }}</span>
                                            · {{ $cj->user }}
                                            @if (! $cj->enabled)
                                                <span class="ml-1 rounded bg-brand-mist/30 px-1.5 py-0.5 text-[0.65rem] font-semibold uppercase text-brand-ink/70">{{ __('Paused') }}</span>
                                            @endif
                                        </p>
                                    </div>
                                    <div class="flex shrink-0 items-center gap-2">
                                        <button
                                            type="button"
                                            wire:click="openLogsModal('{{ $cj->id }}')"
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40"
                                        >
                                            <x-heroicon-o-document-text class="h-4 w-4" />
                                            {{ __('Last output') }}
                                        </button>
                                        <button
                                            type="button"
                                            wire:click="runCronJobNow('{{ $cj->id }}')"
                                            wire:loading.attr="disabled"
                                            class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-3 py-2 text-xs font-semibold text-white shadow-sm hover:bg-brand-forest/90 disabled:opacity-40"
                                            @disabled(! $cj->enabled)
                                        >
                                            <x-heroicon-o-bolt class="h-4 w-4" />
                                            {{ __('Run now') }}
                                        </button>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                <div class="{{ $card }}">
                    <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                        <h2 class="text-sm font-semibold text-brand-ink">{{ __('Live output') }}</h2>
                        <p class="mt-1 text-xs text-brand-moss">
                            {{ __('With Reverb enabled, output streams as it arrives; otherwise the worker’s output cache is polled every second. This panel always shows status — never a blank box.') }}
                        </p>
                    </div>
                    <div
                        class="p-6 sm:p-8"
                        @if ($cron_run_id)
                            wire:poll.1s="syncCronRunFromCache"
                        @endif
                    >
                        <div
                            id="cron-run-meta"
                            class="min-h-[3.5rem] max-h-24 overflow-auto rounded-lg border border-brand-ink/15 bg-brand-sand/40 p-3 text-xs text-brand-ink [scrollbar-color:rgba(15,118,110,0.45)_transparent] [&_pre]:text-[11px] [&_pre]:leading-snug [&_pre]:text-brand-ink"
                            aria-live="polite"
                        >
                            @if ($cron_run_meta_html !== '')
                                {!! $cron_run_meta_html !!}
                            @elseif ($cron_run_id)
                                <p class="text-[11px] text-brand-moss">{{ __('Queued — command details appear when the worker starts the run.') }}</p>
                            @else
                                <p class="text-[11px] text-brand-moss">{{ __('The command you run (and schedule line) will show here.') }}</p>
                            @endif
                        </div>
                        <pre
                            id="cron-run-out"
                            class="mt-3 max-h-[min(50vh,24rem)] min-h-[8rem] overflow-y-auto whitespace-pre-wrap break-all rounded-xl bg-zinc-950 px-4 py-3 font-mono text-xs leading-relaxed text-zinc-100 [scrollbar-color:rgb(82_82_91/0.45)_transparent]"
                            aria-live="polite"
                            role="log"
                        >@if (trim($cron_run_output) !== '')
{{ $cron_run_output }}
@elseif ($cron_run_id)
<span class="inline-flex items-center gap-2">
    <span class="inline-block h-2 w-2 animate-pulse rounded-full bg-emerald-400" aria-hidden="true"></span>
    {{ __('Running…') }}
</span>
@else
<span class="text-zinc-500">{{ __('No active run. Choose a job above and click “Run now” — output streams here.') }}</span>
@endif</pre>
                    </div>
                </div>
            </div>
        </div>

        <div
            @class([
                'hidden' => $cron_workspace_tab !== 'history',
            ])
            role="tabpanel"
            id="cron-panel-history"
            aria-labelledby="cron-tab-history"
            aria-hidden="{{ $cron_workspace_tab !== 'history' ? 'true' : 'false' }}"
        >
            <div class="{{ $card }}">
                <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                    <h2 class="text-sm font-semibold text-brand-ink">{{ __('Run history & audit') }}</h2>
                    <p class="mt-1 text-xs text-brand-moss">{{ __('Recent manual and queued runs (retention :days days).', ['days' => config('cron_workspace.run_retention_days', 90)]) }}</p>
                </div>
                <div class="overflow-x-auto">
                    @if ($recentCronRuns->isEmpty())
                        <p class="px-6 py-10 text-center text-sm text-brand-moss sm:px-8">{{ __('No recorded runs yet. Use “Run now” to create history.') }}</p>
                    @else
                        <table class="min-w-full divide-y divide-brand-ink/10 text-left text-xs">
                            <thead class="bg-brand-sand/30 text-brand-moss">
                                <tr>
                                    <th class="px-4 py-2 font-medium">{{ __('When') }}</th>
                                    <th class="px-4 py-2 font-medium">{{ __('Job') }}</th>
                                    <th class="px-4 py-2 font-medium">{{ __('Status') }}</th>
                                    <th class="px-4 py-2 font-medium">{{ __('Exit') }}</th>
                                    <th class="px-4 py-2 font-medium">{{ __('Duration') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-brand-ink/10 text-brand-ink">
                                @foreach ($recentCronRuns as $run)
                                    <tr>
                                        <td class="whitespace-nowrap px-4 py-2 font-mono text-[11px]">{{ $run->started_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</td>
                                        <td class="max-w-xs truncate px-4 py-2">{{ $run->cronJob?->description ?: \Illuminate\Support\Str::limit($run->cronJob?->command ?? '—', 48) }}</td>
                                        <td class="px-4 py-2">{{ $run->status }}</td>
                                        <td class="px-4 py-2 font-mono">{{ $run->exit_code ?? '—' }}</td>
                                        <td class="px-4 py-2">{{ $run->duration_ms !== null ? $run->duration_ms.' ms' : '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </div>
        </div>

        <div
            @class([
                'hidden' => $cron_workspace_tab !== 'templates',
            ])
            role="tabpanel"
            id="cron-panel-templates"
            aria-labelledby="cron-tab-templates"
            aria-hidden="{{ $cron_workspace_tab !== 'templates' ? 'true' : 'false' }}"
        >
            <div class="space-y-6">
                <div class="{{ $card }}">
                    <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                        <h2 class="text-sm font-semibold text-brand-ink">{{ __('Organization templates') }}</h2>
                        <p class="mt-1 text-xs text-brand-moss">{{ __('Reusable presets for this organization. Apply loads the form on the Jobs tab.') }}</p>
                    </div>
                    @if ($orgCronTemplates->isEmpty())
                        <p class="px-6 py-8 text-sm text-brand-moss sm:px-8">{{ __('No templates yet. Fill the job form and save one below.') }}</p>
                    @else
                        <ul class="divide-y divide-brand-ink/10">
                            @foreach ($orgCronTemplates as $tpl)
                                <li class="flex flex-wrap items-center justify-between gap-3 px-6 py-4 sm:px-8">
                                    <div class="min-w-0">
                                        <p class="font-medium text-brand-ink">{{ $tpl->name }}</p>
                                        <p class="mt-1 font-mono text-xs text-brand-moss">{{ \Illuminate\Support\Str::limit($tpl->command, 100) }}</p>
                                    </div>
                                    <div class="flex gap-2">
                                        <button type="button" wire:click="applyOrgCronTemplate('{{ $tpl->id }}')" class="rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink">{{ __('Apply') }}</button>
                                        @if ($canUpdateOrg)
                                            <button type="button" wire:click="deleteOrgCronTemplate('{{ $tpl->id }}')" wire:confirm="{{ __('Delete this template?') }}" class="rounded-lg px-3 py-1.5 text-xs text-red-600 hover:bg-red-50">{{ __('Delete') }}</button>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @if ($canUpdateOrg)
                        <div class="border-t border-brand-ink/10 px-6 py-4 sm:px-8">
                            <x-input-label for="template_save_name" value="{{ __('Save current form as template') }}" />
                            <div class="mt-2 flex flex-col gap-2 sm:flex-row sm:items-end">
                                <input id="template_save_name" type="text" wire:model="template_save_name" class="block w-full rounded-lg border border-brand-ink/15 px-3 py-2 text-sm" placeholder="{{ __('Template name') }}" />
                                <button type="button" wire:click="saveOrgCronTemplate" class="shrink-0 rounded-lg bg-brand-forest px-4 py-2 text-sm font-semibold text-white">{{ __('Save') }}</button>
                            </div>
                        </div>
                    @endif
                </div>
                @if ($canUpdateOrg)
                    <div class="{{ $card }}">
                        <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                            <h2 class="text-sm font-semibold text-brand-ink">{{ __('Maintenance window') }}</h2>
                            <p class="mt-1 text-xs text-brand-moss">{{ __('Pauses installing managed cron lines for all servers in this organization until the chosen time.') }}</p>
                        </div>
                        <div class="space-y-4 p-6 sm:p-8">
                            <div>
                                <x-input-label for="org_maintenance_until_local" value="{{ __('Until (local app timezone)') }}" />
                                <input id="org_maintenance_until_local" type="datetime-local" wire:model="org_maintenance_until_local" class="mt-1 block w-full rounded-lg border border-brand-ink/15 px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <x-input-label for="org_maintenance_note" value="{{ __('Note (optional)') }}" />
                                <textarea id="org_maintenance_note" wire:model="org_maintenance_note" rows="2" class="mt-1 block w-full rounded-lg border border-brand-ink/15 px-3 py-2 text-sm"></textarea>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <button type="button" wire:click="saveOrgCronMaintenance" class="rounded-lg bg-brand-forest px-4 py-2 text-sm font-semibold text-white">{{ __('Save window') }}</button>
                                <button type="button" wire:click="clearOrgCronMaintenance" class="rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-ink">{{ __('Clear') }}</button>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        <div
            @class([
                'hidden' => $cron_workspace_tab !== 'inspect',
            ])
            role="tabpanel"
            id="cron-panel-inspect"
            aria-labelledby="cron-tab-inspect"
            aria-hidden="{{ $cron_workspace_tab !== 'inspect' ? 'true' : 'false' }}"
        >
            <div class="{{ $card }}">
                <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                    <h2 class="text-sm font-semibold text-brand-ink">{{ __('Crontab on the server') }}</h2>
                    <p class="mt-1 text-xs text-brand-moss leading-relaxed">
                        {{ __('Read-only: shows the real crontab file for that Linux user. Dply uses the SSH login user for “crontab -l”; other users need “sudo crontab -u … -l” (passwordless sudo).') }}
                    </p>
                </div>
                <div class="space-y-4 p-6 sm:p-8">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <div class="min-w-0 flex-1">
                            <x-input-label for="inspect_crontab_user" value="{{ __('Linux user') }}" />
                            <input
                                id="inspect_crontab_user"
                                type="text"
                                wire:model="inspect_crontab_user"
                                autocomplete="off"
                                list="crontab-user-suggestions"
                                class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 font-mono text-sm text-brand-ink shadow-sm placeholder:text-brand-mist focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                                placeholder="{{ __('e.g. deploy, root') }}"
                            />
                            <datalist id="crontab-user-suggestions">
                                @foreach ($crontabInspectUserChoices as $u)
                                    <option value="{{ $u }}"></option>
                                @endforeach
                            </datalist>
                            <x-input-error :messages="$errors->get('inspect_crontab_user')" class="mt-1" />
                        </div>
                        <button
                            type="button"
                            wire:click="loadInspectCrontab"
                            wire:loading.attr="disabled"
                            class="inline-flex shrink-0 items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-50"
                        >
                            <span wire:loading.remove wire:target="loadInspectCrontab">{{ __('Load crontab') }}</span>
                            <span wire:loading wire:target="loadInspectCrontab" class="inline-flex items-center gap-2">
                                <x-spinner variant="forest" />
                                {{ __('Loading…') }}
                            </span>
                        </button>
                    </div>
                    @if ($inspect_crontab_exit_code !== null)
                        <p class="text-xs text-brand-moss">
                            {{ __('Exit code: :code', ['code' => $inspect_crontab_exit_code]) }}
                        </p>
                    @endif
                    <div class="max-h-[min(55vh,28rem)] overflow-auto rounded-xl border border-brand-ink/10 bg-zinc-950">
                        <pre class="whitespace-pre-wrap break-words p-4 font-mono text-xs leading-relaxed text-zinc-100">@if ($inspect_crontab_body !== null){{ $inspect_crontab_body }}@else{{ __('Choose a user and click “Load crontab”.') }}@endif</pre>
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

        @if ($viewingLogJob)
            <div
                class="fixed inset-0 z-50 overflow-y-auto overscroll-y-contain"
                role="dialog"
                aria-modal="true"
                aria-labelledby="cron-logs-title"
            >
                <div class="fixed inset-0 bg-brand-ink/50 backdrop-blur-sm" wire:click="closeLogsModal"></div>
                <div class="relative z-10 flex min-h-full justify-center px-4 py-10 sm:px-6 sm:py-14">
                    <div
                        class="my-auto w-full max-w-2xl rounded-2xl border border-brand-ink/10 bg-white shadow-xl"
                        @click.stop
                        wire:key="cron-logs-dialog"
                    >
                        <div class="flex items-start justify-between gap-4 border-b border-brand-ink/10 px-6 py-5 sm:px-8">
                            <div>
                                <h2 id="cron-logs-title" class="text-lg font-semibold text-brand-ink">{{ __('Last run output') }}</h2>
                                <p class="mt-1 font-mono text-xs text-brand-moss break-all">{{ \Illuminate\Support\Str::limit($viewingLogJob->command, 120) }}</p>
                                @if ($viewingLogJob->last_run_at)
                                    <p class="mt-1 text-xs text-brand-moss">{{ $viewingLogJob->last_run_at->timezone(config('app.timezone'))->format('Y-m-d H:i:s T') }}</p>
                                @else
                                    <p class="mt-1 text-xs text-brand-moss">{{ __('No run recorded yet. Use “Run now” to capture output here.') }}</p>
                                @endif
                            </div>
                            <button
                                type="button"
                                wire:click="closeLogsModal"
                                class="rounded-lg p-2 text-brand-moss hover:bg-brand-sand/50 hover:text-brand-ink"
                            >
                                <x-heroicon-o-x-mark class="h-5 w-5" />
                            </button>
                        </div>
                        <div class="max-h-[min(60vh,28rem)] overflow-auto px-6 py-5 sm:px-8">
                            <pre class="whitespace-pre-wrap break-words rounded-xl bg-zinc-950 px-4 py-3 font-mono text-xs leading-relaxed text-zinc-100">{{ $viewingLogJob->last_run_output ?: __('(empty)') }}</pre>
                        </div>
                        <div class="border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4 text-right sm:px-8">
                            <button
                                type="button"
                                wire:click="closeLogsModal"
                                class="inline-flex items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40"
                            >
                                {{ __('Close') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </x-slot>
</x-server-workspace-layout>
