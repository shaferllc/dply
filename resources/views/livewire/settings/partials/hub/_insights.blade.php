{{-- Servers & sites: the insights block. --}}
            {{-- Insights preferences --}}
            <div class="border-b border-brand-ink/10">
                <x-workspace-panel-head
                    dense
                    icon="heroicon-o-light-bulb"
                    :title="__('Insights preferences')"
                    :note="__('Alert batching and quiet hours. Critical findings still notify immediately.')"
                />
                <form wire:submit="saveOrganizationInsights" class="px-3 py-2.5 sm:px-4">
                    <button type="submit" class="sr-only">{{ __('Save Insights preferences') }}</button>
                    @if (! $currentOrg)
                        <div class="rounded-md border border-amber-200 bg-amber-50 px-2.5 py-1.5 text-xs text-amber-900">
                            {{ __('Create or join an organization to configure these options.') }}
                        </div>
                    @elseif (! $canEditOrgPrefs)
                        <div class="rounded-md border border-brand-ink/10 bg-brand-cream/40 px-2.5 py-1.5 text-xs text-brand-moss">
                            {{ __('Only organization admins can change Insights preferences.') }}
                        </div>
                    @else
                        <div class="space-y-2.5">
                            <div class="divide-y divide-brand-ink/10 overflow-hidden rounded-lg border border-brand-ink/10">
                                <label class="flex cursor-pointer items-start gap-2.5 bg-white px-2.5 py-2 transition-colors hover:bg-brand-sand/15">
                                    <input type="checkbox" wire:model.boolean="organizationInsights.digest_non_critical" class="mt-0.5 h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
                                    <span class="min-w-0 flex-1">
                                        <span class="text-sm font-medium text-brand-ink">{{ __('Digest non-critical findings') }}</span>
                                        <span class="mt-0.5 block text-xs leading-relaxed text-brand-moss">{{ __('Batch warning and info findings into email. Critical stays immediate.') }}</span>
                                    </span>
                                </label>
                                <label class="flex cursor-pointer items-start gap-2.5 bg-white px-2.5 py-2 transition-colors hover:bg-brand-sand/15">
                                    <input type="checkbox" wire:model.boolean="organizationInsights.quiet_hours_enabled" class="mt-0.5 h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
                                    <span class="min-w-0 flex-1">
                                        <span class="text-sm font-medium text-brand-ink">{{ __('Quiet hours for non-critical') }}</span>
                                        <span class="mt-0.5 block text-xs leading-relaxed text-brand-moss">{{ __('Suppress immediate non-critical insight alerts within the window below. Uses the app timezone (:tz).', ['tz' => config('app.timezone')]) }}</span>
                                    </span>
                                </label>
                                <label class="flex cursor-pointer items-start gap-2.5 bg-white px-2.5 py-2 transition-colors hover:bg-brand-sand/15">
                                    <input type="checkbox" wire:model.boolean="organizationInsights.allow_config_mutation" class="mt-0.5 h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
                                    <span class="min-w-0 flex-1">
                                        <span class="text-sm font-medium text-brand-ink">{{ __('Allow Insights to mutate server configs') }}</span>
                                        <span class="mt-0.5 block text-xs leading-relaxed text-brand-moss">{{ __('Apply-fix actions that edit on-disk service configs (e.g. pm.max_children) can run. Restart-only fixes are unaffected. Backups are always taken; revert is one click.') }}</span>
                                    </span>
                                </label>
                            </div>

                            <div class="grid gap-2.5 sm:grid-cols-3">
                                <div>
                                    <label for="org-insights-digest-frequency" class="block text-xs font-semibold text-brand-ink">{{ __('Digest frequency') }}</label>
                                    <select
                                        id="org-insights-digest-frequency"
                                        wire:model="organizationInsights.digest_frequency"
                                        class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white px-2.5 py-1.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                                    >
                                        <option value="daily">{{ __('Daily (08:00)') }}</option>
                                        <option value="weekly">{{ __('Weekly (Mon 08:15)') }}</option>
                                    </select>
                                    @error('organizationInsights.digest_frequency') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="org-insights-quiet-start" class="block text-xs font-semibold text-brand-ink">{{ __('Quiet start (hour)') }}</label>
                                    <input
                                        id="org-insights-quiet-start"
                                        type="number"
                                        min="0"
                                        max="23"
                                        wire:model="organizationInsights.quiet_hours_start"
                                        class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white px-2.5 py-1.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                                    />
                                    @error('organizationInsights.quiet_hours_start') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="org-insights-quiet-end" class="block text-xs font-semibold text-brand-ink">{{ __('Quiet end (hour)') }}</label>
                                    <input
                                        id="org-insights-quiet-end"
                                        type="number"
                                        min="0"
                                        max="23"
                                        wire:model="organizationInsights.quiet_hours_end"
                                        class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white px-2.5 py-1.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                                    />
                                    @error('organizationInsights.quiet_hours_end') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                            </div>
                        </div>
                    @endif
                </form>
            </div>

