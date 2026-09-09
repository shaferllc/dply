{{-- Restore confirmation. The only destructive action in Backups: it overwrites
     a live database in place, and there is no undo beyond another backup. The
     typed confirmation is deliberate friction. --}}
@if ($showRestoreModal)
    @php
        $restoringBackup = $this->restoringBackup();
    @endphp
    @if ($restoringBackup)
        @php
            $sourceName = $restoringBackup->serverDatabase?->name ?? '';
            $typedTarget = trim($restore_target_database);
            $intoName = $typedTarget !== '' ? $typedTarget : $sourceName;
        @endphp
        <div
            class="fixed inset-0 z-50 overflow-y-auto overscroll-y-contain"
            role="dialog"
            aria-modal="true"
            aria-labelledby="restore-modal-title"
            x-data
            x-on:keydown.escape.window="$wire.closeRestoreModal()"
        >
            <div class="fixed inset-0 bg-brand-ink/40" wire:click="closeRestoreModal"></div>
            <div class="relative z-10 flex min-h-full justify-center px-4 py-10 sm:px-6 sm:py-14">
                <div class="my-auto flex w-full max-w-xl flex-col dply-modal-panel overflow-hidden shadow-xl" @click.stop>
                    <div class="flex shrink-0 items-start gap-3 border-b border-brand-ink/10 bg-brand-rust/8 px-6 py-5">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-rust text-brand-cream">
                            <x-heroicon-o-exclamation-triangle class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-rust">{{ __('Destructive') }}</p>
                            <h2 id="restore-modal-title" class="mt-1 text-lg font-semibold text-brand-ink">{{ __('Restore this backup') }}</h2>
                            <p class="mt-1 text-sm leading-6 text-brand-moss">
                                {{ __('This overwrites the target database completely. Anything written since this dump was taken is gone, and there is no undo.') }}
                            </p>
                        </div>
                        <button type="button" wire:click="closeRestoreModal" class="rounded-md p-1 text-brand-mist hover:bg-brand-sand/40 hover:text-brand-ink" aria-label="{{ __('Close') }}">
                            <x-heroicon-o-x-mark class="h-5 w-5" aria-hidden="true" />
                        </button>
                    </div>

                    <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-6 py-6">
                        {{-- What is being restored, stated plainly. --}}
                        <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-brand-ink/10 bg-brand-ink/5 text-sm">
                            <div class="bg-white px-3 py-2">
                                <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('From backup') }}</dt>
                                <dd class="mt-0.5 truncate font-medium text-brand-ink">{{ $restoringBackup->created_at->format('M j, Y H:i') }}</dd>
                            </div>
                            <div class="bg-white px-3 py-2">
                                <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Size') }}</dt>
                                <dd class="mt-0.5 truncate font-mono font-medium tabular-nums text-brand-ink">
                                    {{ $restoringBackup->bytes ? \Illuminate\Support\Number::fileSize((int) $restoringBackup->bytes) : '—' }}
                                </dd>
                            </div>
                            <div class="bg-white px-3 py-2">
                                <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Original database') }}</dt>
                                <dd class="mt-0.5 truncate font-medium text-brand-ink">{{ $sourceName }}</dd>
                            </div>
                            <div class="bg-white px-3 py-2">
                                <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Server') }}</dt>
                                <dd class="mt-0.5 truncate font-medium text-brand-ink">{{ $restoringBackup->serverDatabase?->server?->name ?? '—' }}</dd>
                            </div>
                        </dl>

                        <div>
                            <x-input-label for="restore_target" :value="__('Restore into')" />
                            <x-text-input
                                id="restore_target"
                                type="text"
                                class="mt-1 block w-full"
                                autocomplete="off"
                                placeholder="{{ $sourceName }}"
                                wire:model.live="restore_target_database"
                            />
                            <p class="mt-1 text-xs text-brand-mist">
                                {{ __('Leave blank to restore over :name. Name another database on the same server to restore into that instead — safer if you just want to inspect the data.', ['name' => $sourceName]) }}
                            </p>
                            <x-input-error :messages="$errors->get('restore_target_database')" class="mt-2" />
                        </div>

                        <div class="rounded-xl border border-brand-rust/30 bg-brand-rust/8 p-3">
                            <x-input-label for="restore_confirm" :value="__('Type the database name to confirm')" />
                            <p class="mt-1 text-xs leading-relaxed text-brand-moss">
                                {{ __('You are about to overwrite') }}
                                <span class="font-mono font-semibold text-brand-ink">{{ $intoName }}</span>.
                                {{ __('Type it exactly below.') }}
                            </p>
                            <x-text-input
                                id="restore_confirm"
                                type="text"
                                class="mt-2 block w-full font-mono"
                                autocomplete="off"
                                autocapitalize="off"
                                spellcheck="false"
                                placeholder="{{ $intoName }}"
                                wire:model.live="restore_confirm_name"
                            />
                            <x-input-error :messages="$errors->get('restore_confirm_name')" class="mt-2" />
                        </div>
                    </div>

                    <div class="flex shrink-0 flex-wrap items-center justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                        <x-secondary-button type="button" wire:click="closeRestoreModal">{{ __('Cancel') }}</x-secondary-button>
                        <button
                            type="button"
                            wire:click="confirmRestore"
                            wire:loading.attr="disabled"
                            wire:target="confirmRestore"
                            @disabled($restore_confirm_name !== $intoName)
                            class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-rust px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-rust/90 disabled:cursor-not-allowed disabled:opacity-40"
                        >
                            <span wire:loading.remove wire:target="confirmRestore" class="inline-flex items-center gap-2">
                                <x-heroicon-o-arrow-uturn-left class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Restore over :name', ['name' => $intoName]) }}
                            </span>
                            <span wire:loading wire:target="confirmRestore" class="inline-flex items-center gap-2">
                                <x-spinner variant="cream" size="sm" />
                                {{ __('Queueing…') }}
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
@endif
