{{-- Servers & sites: the timezone block. --}}
            {{-- Your timezone --}}
            <div class="border-b border-brand-ink/10">
                <x-workspace-panel-head
                    dense
                    icon="heroicon-o-clock"
                    :title="__('Your timezone')"
                    :note="__('Used for schedules, Insights quiet hours, and applying a timezone to new servers.')"
                />
                <form wire:submit="saveProfileTimezone" class="px-3 py-2.5 sm:px-4">
                    <button type="submit" class="sr-only">{{ __('Save timezone') }}</button>
                    <x-input-label for="hub-profile-timezone" :value="__('Timezone')" required />
                    <select
                        id="hub-profile-timezone"
                        wire:model="profileTimezone"
                        required
                        class="mt-1 block w-full max-w-md rounded-md border-brand-ink/15 bg-white px-2.5 py-1.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                    >
                        @foreach ($this->timezones as $tz)
                            <option value="{{ $tz }}">{{ $tz }}</option>
                        @endforeach
                    </select>
                    @error('profileTimezone') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </form>
            </div>

            <x-unsaved-changes-bar
                :message="__('You have unsaved changes to your timezone.')"
                saveAction="saveProfileTimezone"
                discardAction="discardProfileTimezoneUnsaved"
                :targets="$profileTimezoneUnsavedTargets"
                :saveLabel="__('Save timezone')"
            />

