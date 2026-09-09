{{-- Profile hub: the preferences block. Extracted so the page body can be
     laid out differently without four copies of the controls. --}}
            <div class="border-b border-brand-ink/10">
                <x-workspace-panel-head
                    dense
                    icon="heroicon-o-adjustments-horizontal"
                    :title="__('Your preferences')"
                    :note="__('Only you see these — not shared with your organization or teams.')"
                />
                <form wire:submit="saveProfile" class="px-3 py-2.5 sm:px-4">
                    <button type="submit" class="sr-only">{{ __('Save settings') }}</button>

                    {{-- One settings list rather than a toggle box followed by
                         loose stacked pickers: every preference is a row with its
                         name + explanation on the left and its control on the
                         right, so the wide column is actually used and the two
                         kinds of setting line up on a single control edge. --}}
                    @php
                        $segmented = fn (bool $on) => $on
                            ? 'inline-flex h-6 items-center gap-1 rounded-md px-2 text-xs font-semibold transition bg-brand-ink text-brand-cream shadow-sm'
                            : 'inline-flex h-6 items-center gap-1 rounded-md px-2 text-xs font-semibold transition text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink';
                        $rowClass = 'flex flex-wrap items-center justify-between gap-x-4 gap-y-1.5 bg-white px-2.5 py-2';
                        $captionClass = 'bg-brand-sand/25 px-2.5 py-1 text-2xs font-semibold uppercase tracking-[0.16em] text-brand-moss';
                    @endphp

                    <div class="divide-y divide-brand-ink/10 overflow-hidden rounded-lg border border-brand-ink/10">
                        <p class="{{ $captionClass }}">{{ __('Appearance & layout') }}</p>

                        <div class="{{ $rowClass }}">
                            <div class="min-w-0 flex-1 basis-64">
                                <p class="text-sm font-medium text-brand-ink">{{ __('Theme mode') }}</p>
                                <p class="mt-0.5 text-xs leading-relaxed text-brand-moss">{{ __('Choose an appearance or follow your system setting.') }}</p>
                                @error('ui.theme') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div class="inline-flex shrink-0 flex-wrap gap-1 rounded-lg border border-brand-ink/10 bg-white p-0.5 shadow-sm">
                                @foreach ($themeOptions as $opt)
                                    <button type="button" wire:click="persistTheme('{{ $opt }}')" class="{{ $segmented(($ui['theme'] ?? '') === $opt) }}">
                                        @if ($opt === 'light')
                                            <x-heroicon-o-sun class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                            {{ __('Light') }}
                                        @elseif ($opt === 'dark')
                                            <x-heroicon-o-moon class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                            {{ __('Dark') }}
                                        @else
                                            <x-heroicon-o-computer-desktop class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                            {{ __('System') }}
                                        @endif
                                    </button>
                                @endforeach
                            </div>
                        </div>

                        <div class="{{ $rowClass }}">
                            <div class="min-w-0 flex-1 basis-64">
                                <p class="text-sm font-medium text-brand-ink">{{ __('Navigation layout') }}</p>
                                <p class="mt-0.5 text-xs leading-relaxed text-brand-moss">{{ __('Sidebar on large screens, or a horizontal link row under the header.') }}</p>
                                @error('ui.navigation_layout') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div class="inline-flex shrink-0 flex-wrap gap-1 rounded-lg border border-brand-ink/10 bg-white p-0.5 shadow-sm">
                                @foreach ($navLayoutOptions as $opt)
                                    <button type="button" wire:click="persistNavigationLayout('{{ $opt }}')" class="{{ $segmented(($ui['navigation_layout'] ?? '') === $opt) }}">
                                        @if ($opt === 'sidebar')
                                            <x-heroicon-o-squares-2x2 class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                            {{ __('Sidebar') }}
                                        @else
                                            <x-heroicon-o-bars-3 class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                            {{ __('Top') }}
                                        @endif
                                    </button>
                                @endforeach
                            </div>
                        </div>

                        <div class="{{ $rowClass }}">
                            <div class="min-w-0 flex-1 basis-64">
                                <label for="notification-position" class="text-sm font-medium text-brand-ink">{{ __('Notification position') }}</label>
                                <p class="mt-0.5 text-xs leading-relaxed text-brand-moss">{{ __('Where toast notifications appear on screen.') }}</p>
                                @error('ui.notification_position') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div class="flex shrink-0 items-center gap-1.5">
                                <select
                                    id="notification-position"
                                    wire:model="ui.notification_position"
                                    class="h-7 w-44 rounded-md border-brand-ink/15 bg-white py-0 ps-2.5 pe-8 text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                                >
                                    @foreach (config('user_preferences.notification_positions', []) as $value => $label)
                                        <option value="{{ $value }}">{{ __($label) }}</option>
                                    @endforeach
                                </select>
                                <button
                                    type="button"
                                    data-notification-preview-message="{{ __('This is where notifications will appear.') }}"
                                    onclick="window.dispatchEvent(new CustomEvent('toast', { detail: { message: this.dataset.notificationPreviewMessage, type: 'success', position: document.getElementById('notification-position').value } }))"
                                    class="inline-flex h-7 shrink-0 items-center justify-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                                >
                                    <x-heroicon-o-paper-airplane class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    {{ __('Test') }}
                                </button>
                            </div>
                        </div>

                        <p class="{{ $captionClass }}">{{ __('Email & behavior') }}</p>

                        {{-- Checkbox sits in the same right-hand control column as
                             the pickers above, so the list reads as one column of
                             settings and one column of controls. --}}
                        @foreach ([
                            ['key' => 'newsletter', 'title' => __('Receive newsletter'), 'desc' => __('Product updates only — no spam.')],
                            ['key' => 'keyboard_shortcuts', 'title' => __('Enable keyboard shortcuts'), 'desc' => __('Turns keyboard shortcuts on or off in the app.')],
                            ['key' => 'redirect_home_to_app', 'title' => __('Redirect to app when logged in'), 'desc' => __('Visiting the marketing homepage signed in sends you to the dashboard.')],
                            ['key' => 'subscription_invoice_emails', 'title' => __('Subscription invoice emails'), 'desc' => __('When your org moves from trial to Pro, include Stripe invoice PDFs in email.')],
                        ] as $toggle)
                            <label class="{{ $rowClass }} cursor-pointer transition-colors hover:bg-brand-sand/15">
                                <span class="min-w-0 flex-1 basis-64">
                                    <span class="text-sm font-medium text-brand-ink">{{ $toggle['title'] }}</span>
                                    <span class="mt-0.5 block text-xs leading-relaxed text-brand-moss">{{ $toggle['desc'] }}</span>
                                </span>
                                <input type="checkbox" wire:model.boolean="ui.{{ $toggle['key'] }}" class="h-4 w-4 shrink-0 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
                            </label>
                        @endforeach
                    </div>
                </form>
            </div>

