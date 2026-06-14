{{--
    Global feedback / bug-report sidebar. Single Livewire root (LW4 rule).
    The `.dply-feedback-sidebar` class is in the screenshot redaction allowlist
    so the panel never captures itself.
--}}
<div class="dply-feedback-sidebar" x-data="dplyFeedbackSidebar()" data-feedback-redact>
    {{-- Floating launcher — sits just above the Console pill, bottom-right. --}}
    <button
        type="button"
        x-on:click="toggle()"
        x-show="!open"
        class="fixed bottom-20 right-4 z-40 inline-flex items-center gap-1.5 rounded-full border border-brand-ink/15 bg-white/95 px-3.5 py-2 text-xs font-semibold text-brand-ink shadow-lg shadow-brand-ink/10 backdrop-blur hover:bg-brand-sand/40 focus:outline-none focus:ring-2 focus:ring-brand-sage/40"
        title="{{ __('Send feedback or report a bug') }}"
    >
        <x-heroicon-o-chat-bubble-left-right class="h-4 w-4 shrink-0 text-brand-moss" aria-hidden="true" />
        {{ __('Feedback') }}
    </button>

    {{-- Backdrop --}}
    <div
        x-show="open"
        x-cloak
        x-transition.opacity
        class="fixed inset-0 z-40 bg-brand-ink/30 backdrop-blur-sm"
        x-on:click="close()"
        aria-hidden="true"
    ></div>

    {{-- Slide-over panel (right edge) --}}
    <div
        x-show="open"
        x-cloak
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="translate-x-full opacity-0"
        x-transition:enter-end="translate-x-0 opacity-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="translate-x-0 opacity-100"
        x-transition:leave-end="translate-x-full opacity-0"
        x-on:keydown.escape.window="close()"
        class="fixed inset-y-0 right-0 z-50 flex w-full max-w-md flex-col border-l border-brand-ink/10 bg-white shadow-2xl shadow-brand-ink/20"
        role="dialog"
        aria-modal="true"
        aria-labelledby="feedback-sidebar-title"
    >
        {{-- Header --}}
        <div class="flex shrink-0 items-center justify-between gap-3 border-b border-brand-ink/10 bg-brand-cream/60 px-5 py-3.5">
            <div class="flex min-w-0 items-center gap-2.5">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-chat-bubble-left-right class="h-4 w-4" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p id="feedback-sidebar-title" class="truncate text-sm font-semibold text-brand-ink">{{ __('Send feedback') }}</p>
                    <p class="truncate text-[11px] text-brand-moss">{{ __('Found a bug or have an idea? Tell us.') }}</p>
                </div>
            </div>
            <button
                type="button"
                x-on:click="close()"
                class="inline-flex shrink-0 items-center justify-center rounded-lg border border-brand-ink/15 bg-white p-1.5 text-brand-moss shadow-sm hover:bg-brand-sand/40 hover:text-brand-ink"
                title="{{ __('Close (Esc)') }}"
            >
                <x-heroicon-o-x-mark class="h-4 w-4" aria-hidden="true" />
            </button>
        </div>

        {{-- Body --}}
        <form x-on:submit.prevent="submitWithCapture()" class="flex min-h-0 flex-1 flex-col">
            <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-5">
                {{-- Type selector --}}
                <div>
                    <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('What kind of feedback?') }}</label>
                    <div class="grid grid-cols-3 gap-2">
                        @foreach ($types as $key => $label)
                            <button
                                type="button"
                                wire:click="$set('type', '{{ $key }}')"
                                @class([
                                    'rounded-lg border px-2.5 py-2 text-sm font-medium transition-colors',
                                    'border-brand-forest bg-brand-sage/15 text-brand-forest' => false,
                                ])
                                x-bind:class="$wire.type === '{{ $key }}'
                                    ? 'border-brand-forest bg-brand-sage/15 text-brand-forest'
                                    : 'border-brand-ink/15 bg-white text-brand-moss hover:bg-brand-sand/30'"
                            >
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>
                </div>

                {{-- Severity (bugs only) --}}
                <div x-show="$wire.type === 'bug'" x-cloak>
                    <label for="feedback-severity" class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Severity') }}</label>
                    <select
                        id="feedback-severity"
                        wire:model="severity"
                        class="w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink focus:border-brand-sage focus:ring-brand-sage/30"
                    >
                        @foreach ($severities as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Title --}}
                <div>
                    <label for="feedback-title" class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Summary') }}</label>
                    <input
                        id="feedback-title"
                        type="text"
                        wire:model="title"
                        maxlength="200"
                        placeholder="{{ __('A short one-liner') }}"
                        class="w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink placeholder:text-brand-mist focus:border-brand-sage focus:ring-brand-sage/30"
                    />
                    @error('title') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                {{-- Description --}}
                <div>
                    <label for="feedback-description" class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Details') }}</label>
                    <textarea
                        id="feedback-description"
                        wire:model="description"
                        rows="5"
                        placeholder="{{ __('What happened? What did you expect? Steps to reproduce help a lot.') }}"
                        class="w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink placeholder:text-brand-mist focus:border-brand-sage focus:ring-brand-sage/30"
                    ></textarea>
                    @error('description') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                {{-- Attachments --}}
                <div>
                    <label for="feedback-attachments" class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Attachments') }} <span class="font-normal normal-case text-brand-mist">({{ __('optional, images') }})</span></label>
                    <input
                        id="feedback-attachments"
                        type="file"
                        wire:model="attachments"
                        multiple
                        accept="image/*"
                        class="block w-full text-xs text-brand-moss file:mr-3 file:rounded-lg file:border-0 file:bg-brand-sand/60 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-brand-ink hover:file:bg-brand-sand"
                    />
                    <div wire:loading wire:target="attachments" class="mt-1 text-xs text-brand-moss">{{ __('Uploading…') }}</div>
                    @error('attachments.*') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    @if (count($attachments))
                        <p class="mt-1 text-xs text-brand-moss">{{ trans_choice(':count image attached|:count images attached', count($attachments), ['count' => count($attachments)]) }}</p>
                    @endif
                </div>

                {{-- Auto-capture notice + screenshot toggle --}}
                <div class="rounded-lg border border-brand-ink/10 bg-brand-cream/50 px-3 py-2.5">
                    <label class="flex items-start gap-2.5">
                        <input type="checkbox" x-model="includeScreenshot" class="mt-0.5 rounded border-brand-ink/25 text-brand-forest focus:ring-brand-sage/30" />
                        <span class="text-xs text-brand-moss">
                            <span class="font-semibold text-brand-ink">{{ __('Include a screenshot of this page') }}</span><br>
                            {{ __('We also attach the page URL and recent browser errors to help us debug. Secrets and console output are redacted from screenshots.') }}
                        </span>
                    </label>
                </div>
            </div>

            {{-- Footer --}}
            <div class="flex shrink-0 items-center justify-end gap-2 border-t border-brand-ink/10 bg-brand-cream/40 px-5 py-3">
                <button
                    type="button"
                    x-on:click="close()"
                    class="rounded-lg border border-brand-ink/15 bg-white px-3.5 py-2 text-sm font-medium text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink"
                >
                    {{ __('Cancel') }}
                </button>
                <button
                    type="button"
                    x-on:click="submitWithCapture()"
                    x-bind:disabled="busy"
                    data-skip-busy="1"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-ink/90 focus:outline-none focus:ring-2 focus:ring-brand-sage/40 disabled:opacity-60"
                >
                    <svg x-show="busy" x-cloak class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                    </svg>
                    <span x-text="busy ? '{{ __('Sending…') }}' : '{{ __('Send report') }}'">{{ __('Send report') }}</span>
                </button>
            </div>
        </form>
    </div>
</div>
