@if ($showConfirmActionModal ?? false)
    @teleport('body')
    <div
        class="fixed inset-0 isolate z-[100] overflow-y-auto"
        role="dialog"
        aria-modal="true"
        aria-labelledby="confirm-action-modal-title"
        x-data="{
            focusables() {
                let selector = 'a, button, input:not([type=\'hidden\']), textarea, select, details, [tabindex]:not([tabindex=\'-1\'])'
                return [...$el.querySelectorAll(selector)].filter(el => ! el.hasAttribute('disabled'))
            },
            firstFocusable() { return this.focusables()[0] },
            lastFocusable() { return this.focusables().slice(-1)[0] },
            nextFocusable() { return this.focusables()[this.nextFocusableIndex()] || this.firstFocusable() },
            prevFocusable() { return this.focusables()[this.prevFocusableIndex()] || this.lastFocusable() },
            nextFocusableIndex() { return (this.focusables().indexOf(document.activeElement) + 1) % (this.focusables().length + 1) },
            prevFocusableIndex() { return Math.max(0, this.focusables().indexOf(document.activeElement)) - 1 },
            close() {
                document.body.classList.remove('overflow-y-hidden')
                $wire.closeConfirmActionModal()
            },
        }"
        x-init="
            document.body.classList.add('overflow-y-hidden');
            setTimeout(() => firstFocusable()?.focus(), 100);
            return () => document.body.classList.remove('overflow-y-hidden')
        "
        x-on:keydown.escape.window="close()"
        x-on:keydown.tab.prevent="!$event.shiftKey && nextFocusable().focus()"
        x-on:keydown.shift.tab.prevent="prevFocusable().focus()"
    >
        <div class="fixed inset-0 z-0 bg-brand-ink/50 backdrop-blur-sm" x-on:click="close()"></div>
        <div class="relative z-10 flex min-h-full items-center justify-center px-4 py-10 sm:px-6">
            <x-dialog-shell :title="$confirmActionModalTitle" title-id="confirm-action-modal-title" max-width="md">
                <div class="space-y-4">
                    <p class="text-sm leading-relaxed text-brand-moss">{{ $confirmActionModalMessage }}</p>
                    @if (! empty($confirmActionModalDetails))
                        <dl class="divide-y divide-brand-ink/8 rounded-xl border border-brand-ink/10 bg-brand-sand/15 text-sm">
                            @foreach ($confirmActionModalDetails as $row)
                                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 px-4 py-2.5" wire:key="confirm-detail-{{ $loop->index }}">
                                    <dt class="w-36 shrink-0 text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ $row['label'] }}</dt>
                                    <dd @class([
                                        'min-w-0 flex-1 break-all text-brand-ink',
                                        'font-mono text-xs' => ! empty($row['mono']),
                                        'whitespace-pre-wrap text-sm leading-relaxed' => ! empty($row['multiline']),
                                    ])>
                                        @if (! empty($row['link']))
                                            <a href="{{ $row['value'] }}" target="_blank" rel="noopener noreferrer" class="font-medium text-brand-forest underline-offset-2 hover:underline">{{ $row['value'] }}</a>
                                        @else
                                            {{ $row['value'] }}
                                        @endif
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                    @endif

                    @if (! empty($confirmActionModalToggleLabel))
                        <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-rose-200 bg-rose-50/60 px-4 py-3">
                            <input type="checkbox" wire:model.live="confirmActionModalToggle"
                                class="mt-0.5 h-4 w-4 shrink-0 rounded border-rose-300 text-rose-600 focus:ring-rose-500/40" />
                            <span class="min-w-0">
                                <span class="block text-sm font-semibold text-rose-800">{{ $confirmActionModalToggleLabel }}</span>
                                @if (! empty($confirmActionModalToggleHint))
                                    <span class="mt-0.5 block text-xs leading-relaxed text-rose-700/90">{{ $confirmActionModalToggleHint }}</span>
                                @endif
                            </span>
                        </label>
                    @endif
                </div>

                <x-slot name="footer">
                    <x-secondary-button type="button" x-on:click="close()">
                        {{ __('Cancel') }}
                    </x-secondary-button>

                    @if ($confirmActionModalDestructive ?? false)
                        <x-danger-button type="button" wire:click="confirmActionModal" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="confirmActionModal">{{ $confirmActionModalConfirmLabel }}</span>
                            <span wire:loading wire:target="confirmActionModal">{{ __('Working…') }}</span>
                        </x-danger-button>
                    @else
                        <x-primary-button type="button" wire:click="confirmActionModal" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="confirmActionModal">{{ $confirmActionModalConfirmLabel }}</span>
                            <span wire:loading wire:target="confirmActionModal">{{ __('Working…') }}</span>
                        </x-primary-button>
                    @endif
                </x-slot>
            </x-dialog-shell>
        </div>
    </div>
    @endteleport
@endif
