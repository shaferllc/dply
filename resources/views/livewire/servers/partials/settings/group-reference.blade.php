<section id="settings-group-reference" class="space-y-6" aria-labelledby="settings-group-reference-title">
    <div id="settings-timezone" class="{{ $card }} scroll-mt-24">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-clock class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Timezone') }}</p>
                <h3 id="settings-group-reference-title" class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Display timezone') }}</h3>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                    {{ __('Used when showing times in this workspace. The guest OS keeps its own timezone unless you change it over SSH.') }}
                </p>
            </div>
        </div>
        <div class="px-6 py-6 sm:px-7">
        <form wire:submit="saveServerTimezone" class="max-w-md">
            <x-input-label for="settings-tz" value="{{ __('Timezone') }}" />
            <select
                id="settings-tz"
                wire:model="settingsTimezone"
                class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                @disabled(! $this->canEditServerSettings)
            >
                @if ($this->settingsTimezone !== '' && ! in_array($this->settingsTimezone, $tzPreset, true))
                    <option value="{{ $this->settingsTimezone }}">{{ $this->settingsTimezone }} ({{ __('current') }})</option>
                @endif
                @foreach ($tzPreset as $tz)
                    <option value="{{ $tz }}">{{ $tz }}</option>
                @endforeach
            </select>
            <x-input-error :messages="$errors->get('settingsTimezone')" class="mt-2" />
            @if ($this->canEditServerSettings)
                <x-primary-button type="submit" class="mt-4" wire:loading.attr="disabled">{{ __('Save timezone') }}</x-primary-button>
            @endif
        </form>
        </div>
    </div>

    <div id="settings-date-format" class="{{ $card }} scroll-mt-24">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-clock class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Timezone') }}</p>
                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Date format') }}</h3>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                    {{ __('Controls how this server\'s timestamps render across the workspace — last sample, deploys, audit log, etc. Saved on the server, so different servers can use different formats.') }}
                </p>
            </div>
        </div>
        <div class="px-6 py-6 sm:px-7">
        <form wire:submit="saveServerDateFormat" class="max-w-md">
            <x-input-label for="settings-date-format-select" value="{{ __('Format') }}" />
            <select
                id="settings-date-format-select"
                wire:model="settingsDateFormat"
                class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                @disabled(! $this->canEditServerSettings)
            >
                @foreach (config('server_settings.date_formats', []) as $key => $option)
                    <option value="{{ $key }}">{{ $option['label'] }} — {{ $option['sample'] }}</option>
                @endforeach
            </select>
            <x-input-error :messages="$errors->get('settingsDateFormat')" class="mt-2" />
            @php
                $previewSample = config('server_settings.date_formats.'.$this->settingsDateFormat.'.sample')
                    ?? config('server_settings.date_formats.absolute_utc.sample');
            @endphp
            <p class="mt-3 text-xs text-brand-mist">{{ __('Preview:') }} <span class="font-mono text-brand-ink">{{ $previewSample }}</span></p>
            @if ($this->canEditServerSettings)
                <x-primary-button type="submit" class="mt-4" wire:loading.attr="disabled">{{ __('Save format') }}</x-primary-button>
            @endif
        </form>
        </div>
    </div>

    <div id="settings-notes" class="{{ $card }} scroll-mt-24">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-document-text class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Notes') }}</p>
                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Internal notes') }}</h3>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Free-form context: runbooks, customer IDs, things the next engineer should know.') }}</p>
            </div>
        </div>
        <div class="px-6 py-6 sm:px-7">
        <form wire:submit="saveServerNotes">
            <textarea
                wire:model="settingsNotes"
                rows="6"
                class="block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                @disabled(! $this->canEditServerSettings)
            ></textarea>
            <x-input-error :messages="$errors->get('settingsNotes')" class="mt-2" />
            @if ($this->canEditServerSettings)
                <x-primary-button type="submit" class="mt-4" wire:loading.attr="disabled">{{ __('Save notes') }}</x-primary-button>
            @endif
        </form>
        </div>
    </div>
</section>
