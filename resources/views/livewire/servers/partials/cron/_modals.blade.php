<x-modal name="add-cron-job-modal" maxWidth="3xl" overlayClass="bg-brand-ink/40">
    <div class="border-b border-brand-ink/10 px-6 py-5">
        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Cron job') }}</p>
        <h2 class="mt-2 text-xl font-semibold text-brand-ink">
            @if ($editing_job_id)
                {{ __('Edit cron job') }}
            @else
                {{ __('New cron job') }}
            @endif
        </h2>
        <p class="mt-2 text-sm leading-6 text-brand-moss">
            {{ __('What should run, which user runs it, and how often. Advanced toggles tuck below when you need them.') }}
        </p>
    </div>
    <div class="p-6 sm:p-8">
        <form id="cron-job-form" wire:submit="saveCronJob" class="space-y-6">
            <div>
                @if (! empty($artisanCommandPresets))
                    <div class="mb-3">
                        <label for="cron_common_command" class="block text-xs font-medium text-brand-moss">
                            {{ __('Common commands') }}
                        </label>
                        <select
                            id="cron_common_command"
                            x-data
                            x-on:change="if ($event.target.value) { $wire.applyArtisanCommandPreset($event.target.value); $event.target.value = '' }"
                            class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                        >
                            <option value="">{{ __('Insert a preset…') }}</option>
                            @foreach ($artisanCommandPresets as $group => $items)
                                <optgroup label="{{ $group }}">
                                    @foreach ($items as $item)
                                        <option value="{{ $item['key'] }}">{{ $item['label'] }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-brand-moss">
                            {{ __('Fills the Command field below. Laravel paths resolve to the selected site when one is attached; otherwise edit the template before saving.') }}
                        </p>
                    </div>
                @endif
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
                @if ($schedulerSiteIsLaravel)
                    <button
                        type="button"
                        wire:click="fillLaravelSchedulerCommand"
                        class="mt-3 inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-brand-sand/30 px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/50"
                    >
                        <x-heroicon-o-sparkles class="h-4 w-4" aria-hidden="true" />
                        {{ __('Use Laravel scheduler for this site (schedule:run)') }}
                    </button>
                @endif
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
                        <span wire:loading.remove wire:target="refreshRunAsUserChoices" class="inline-flex items-center gap-1.5">
                            <x-heroicon-o-arrow-path class="h-4 w-4" aria-hidden="true" />
                            {{ __('Refresh list') }}
                        </span>
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
                <summary class="cursor-pointer select-none px-4 py-3 text-sm font-medium text-brand-ink">{{ __('Advanced options') }}</summary>
                <div class="space-y-4 border-t border-brand-ink/10 px-4 py-4">
                    <div>
                        <x-input-label for="command_preset" value="{{ __('Starter command') }}" />
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
                            {{ __('Fill the form with a common starter, then adjust paths, domains, and users before saving.') }}
                        </p>
                    </div>
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
                        <button type="button" wire:click="validateCronExpressionField" wire:loading.attr="disabled" wire:target="validateCronExpressionField" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-50">
                            <x-heroicon-o-check-circle class="h-4 w-4" wire:loading.remove wire:target="validateCronExpressionField" aria-hidden="true" />
                            <x-spinner wire:loading wire:target="validateCronExpressionField" variant="forest" size="sm" />
                            {{ __('Validate expression') }}
                        </button>
                        <button type="button" wire:click="dryRunFormCommand" wire:loading.attr="disabled" wire:target="dryRunFormCommand" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-50">
                            <x-heroicon-o-play class="h-4 w-4" wire:loading.remove wire:target="dryRunFormCommand" aria-hidden="true" />
                            <x-spinner wire:loading wire:target="dryRunFormCommand" variant="forest" size="sm" />
                            {{ __('Dry run (preview shell)') }}
                        </button>
                    </div>
                </div>
            </details>
        </form>

        @if ($canUpdateOrg)
            <div class="mt-6 rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                <p class="text-sm font-semibold text-brand-ink">{{ __('Save as organization template') }}</p>
                <p class="mt-1 text-xs text-brand-moss">{{ __('Stores the expression, command, user, and description above as a named template your team can apply from the Templates tab.') }}</p>
                <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-start">
                    <div class="min-w-0 flex-1">
                        <x-input-label for="template_save_name" value="{{ __('Template name') }}" class="sr-only" />
                        <x-text-input
                            id="template_save_name"
                            wire:model="template_save_name"
                            class="block w-full text-sm"
                            placeholder="{{ __('e.g. Laravel scheduler') }}"
                            maxlength="120"
                        />
                        <x-input-error :messages="$errors->get('template_save_name')" class="mt-1" />
                    </div>
                    <button
                        type="button"
                        wire:click="saveOrgCronTemplate"
                        wire:loading.attr="disabled"
                        wire:target="saveOrgCronTemplate"
                        class="inline-flex shrink-0 items-center justify-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <span wire:loading.remove wire:target="saveOrgCronTemplate" class="inline-flex items-center gap-1.5">
                            <x-heroicon-o-bookmark-square class="h-4 w-4" />
                            {{ __('Save as template') }}
                        </span>
                        <span wire:loading wire:target="saveOrgCronTemplate" class="inline-flex items-center gap-1.5">
                            <x-spinner variant="forest" size="sm" />
                            {{ __('Saving…') }}
                        </span>
                    </button>
                </div>
            </div>
        @endif
    </div>
    <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 bg-brand-sand/15 px-6 py-4">
        @if ($editing_job_id)
            <x-secondary-button type="button" wire:click="cancelEdit" x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
        @else
            <x-secondary-button type="button" x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
        @endif
        <x-primary-button type="submit" form="cron-job-form" wire:loading.attr="disabled" wire:target="saveCronJob">
            <span wire:loading.remove wire:target="saveCronJob">
                @if ($editing_job_id)
                    {{ __('Save changes') }}
                @else
                    {{ __('Add cron job') }}
                @endif
            </span>
            <span wire:loading wire:target="saveCronJob">{{ __('Saving…') }}</span>
        </x-primary-button>
    </div>
</x-modal>
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
                class="my-auto w-full max-w-2xl dply-modal-panel"
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
