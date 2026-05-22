{{--
    Cron Maintenance tab content.

    Org-level controls for pausing the Dply-managed cron block across the
    organization for a defined window. Only admins reach this view; tab is
    hidden in the tablist for everyone else.

    Required vars: $card, $server.
--}}

<div class="{{ $card }}">
    <div class="flex flex-col gap-3 border-b border-brand-ink/10 px-6 py-5 sm:px-8">
        <div class="flex min-w-0 items-start gap-3">
            <span class="mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-amber-50 text-amber-800 ring-1 ring-amber-200">
                <x-heroicon-o-wrench class="h-5 w-5" />
            </span>
            <div class="min-w-0">
                <h2 class="text-lg font-semibold text-brand-ink">{{ __('Organization maintenance window') }}</h2>
                <p class="mt-0.5 text-sm text-brand-moss leading-relaxed">
                    {{ __('When set, Dply omits managed cron lines from every server in the organization on the next sync until the window ends. Manual “Run now” is blocked while active. Use during deploys, migrations, or maintenance windows.') }}
                </p>
            </div>
        </div>
    </div>
    <div class="space-y-6 p-6 sm:p-8">
        @if ($server->organization?->cron_maintenance_until && now()->lt($server->organization->cron_maintenance_until))
            <div class="rounded-xl border border-amber-300/80 bg-amber-50/70 px-4 py-3 text-sm text-amber-950">
                <p class="font-semibold">{{ __('Maintenance window active') }}</p>
                <p class="mt-1 text-amber-900/90">
                    {{ __('Active until :time.', ['time' => $server->organization->cron_maintenance_until->timezone(config('app.timezone'))->format('Y-m-d H:i T')]) }}
                    @if (filled($server->organization->cron_maintenance_note))
                        — {{ $server->organization->cron_maintenance_note }}
                    @endif
                </p>
            </div>
        @endif

        <form wire:submit="saveOrgCronMaintenance" class="space-y-5">
            <div>
                <x-input-label for="org_maintenance_until_local" value="{{ __('Pause managed cron until') }}" />
                <input
                    id="org_maintenance_until_local"
                    type="datetime-local"
                    wire:model="org_maintenance_until_local"
                    class="mt-1 block w-full max-w-md rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                />
                <p class="mt-1.5 text-xs text-brand-moss">{{ __('Times are interpreted in :tz. Leave empty to clear the window.', ['tz' => config('app.timezone')]) }}</p>
                <x-input-error :messages="$errors->get('org_maintenance_until_local')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="org_maintenance_note" value="{{ __('Note (shown in the banner)') }}" />
                <textarea
                    id="org_maintenance_note"
                    wire:model="org_maintenance_note"
                    rows="2"
                    maxlength="500"
                    class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                    placeholder="{{ __('e.g. cluster upgrade in progress — back online by 18:00 UTC') }}"
                ></textarea>
                <x-input-error :messages="$errors->get('org_maintenance_note')" class="mt-1" />
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="saveOrgCronMaintenance">
                    <span wire:loading.remove wire:target="saveOrgCronMaintenance">{{ __('Save maintenance window') }}</span>
                    <span wire:loading wire:target="saveOrgCronMaintenance">{{ __('Saving…') }}</span>
                </x-primary-button>
                @if ($server->organization?->cron_maintenance_until || filled($server->organization?->cron_maintenance_note))
                    <button
                        type="button"
                        wire:click="clearOrgCronMaintenance"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40"
                    >
                        <x-heroicon-o-x-mark class="h-4 w-4" />
                        {{ __('Clear window') }}
                    </button>
                @endif
            </div>
        </form>
    </div>
</div>
