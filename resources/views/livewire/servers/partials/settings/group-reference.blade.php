<section id="settings-group-reference" class="space-y-6" aria-labelledby="settings-group-reference-title">
    @include('livewire.servers.partials.settings._intro', [
        'headingId' => 'settings-group-reference-title',
        'kicker' => __('Reference'),
        'title' => __('Timezone & notes'),
        'description' => __('Timezone is stored for humans interpreting maintenance windows and timestamps in Dply—it does not change the Linux clock. Notes are visible to your whole organization.'),
    ])

    <div id="settings-timezone" class="{{ $card }} scroll-mt-24 p-6 sm:p-8">
        <h3 class="text-lg font-semibold text-brand-ink">{{ __('Display timezone') }}</h3>
        <p class="mt-2 text-sm text-brand-moss">
            {{ __('Used when showing times in this workspace. The guest OS keeps its own timezone unless you change it over SSH.') }}
        </p>
        <form wire:submit="saveServerTimezone" class="mt-6 max-w-md">
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

    <div id="settings-notes" class="{{ $card }} scroll-mt-24 p-6 sm:p-8">
        <h3 class="text-lg font-semibold text-brand-ink">{{ __('Internal notes') }}</h3>
        <p class="mt-2 text-sm text-brand-moss">{{ __('Free-form context: runbooks, customer IDs, things the next engineer should know.') }}</p>
        <form wire:submit="saveServerNotes" class="mt-6">
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
</section>
