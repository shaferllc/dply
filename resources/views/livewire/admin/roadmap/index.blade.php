<div>
    <x-page-header
        :title="__('Roadmap')"
        :description="__('Manage public roadmap items and review private user suggestions.')"
        flush
        compact
    />

    <div class="mb-6 flex flex-wrap items-center gap-2">
        <button
            type="button"
            wire:click="setTab('items')"
            @class([
                'rounded-lg px-3 py-2 text-sm font-medium transition',
                $tab === 'items' ? 'bg-brand-sand/70 text-brand-ink border border-brand-ink/10 shadow-sm' : 'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink border border-transparent',
            ])
        >
            {{ __('Items') }}
        </button>
        <button
            type="button"
            wire:click="setTab('suggestions')"
            @class([
                'inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition',
                $tab === 'suggestions' ? 'bg-brand-sand/70 text-brand-ink border border-brand-ink/10 shadow-sm' : 'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink border border-transparent',
            ])
        >
            {{ __('Suggestions') }}
            @if ($newSuggestionCount > 0)
                <span class="rounded-full bg-brand-rust/15 px-2 py-0.5 text-xs font-semibold text-brand-rust">{{ $newSuggestionCount }}</span>
            @endif
        </button>
        @if ($tab === 'items')
            <button type="button" wire:click="openCreateItemModal" class="ms-auto inline-flex items-center gap-2 rounded-lg bg-brand-forest px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-brand-forest/90">
                <x-heroicon-o-plus class="h-4 w-4" />
                {{ __('Add item') }}
            </button>
        @endif
    </div>

    @if ($tab === 'items')
        <div class="space-y-8">
            @foreach ($statusLabels as $statusKey => $statusLabel)
                @php $statusItems = $itemsByStatus->get($statusKey, collect()); @endphp
                <section wire:key="admin-roadmap-status-{{ $statusKey }}">
                    <h2 class="mb-3 text-sm font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __($statusLabel) }}</h2>
                    <div class="overflow-hidden rounded-xl border border-brand-ink/10 bg-white">
                        <table class="min-w-full divide-y divide-brand-ink/10 text-left text-sm">
                            <thead class="bg-brand-sand/40 text-xs text-brand-moss">
                                <tr>
                                    <th class="px-3 py-2 font-medium">{{ __('Title') }}</th>
                                    <th class="px-3 py-2 font-medium">{{ __('Area') }}</th>
                                    <th class="px-3 py-2 font-medium">{{ __('Published') }}</th>
                                    <th class="px-3 py-2 font-medium">{{ __('Order') }}</th>
                                    <th class="px-3 py-2 font-medium text-end">{{ __('Actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-brand-ink/5">
                                @forelse ($statusItems as $item)
                                    <tr wire:key="admin-roadmap-item-{{ $item->id }}">
                                        <td class="px-3 py-3">
                                            <p class="font-medium text-brand-ink">{{ $item->title }}</p>
                                            @if ($item->summary)
                                                <p class="mt-1 text-xs text-brand-moss">{{ $item->summary }}</p>
                                            @endif
                                        </td>
                                        <td class="px-3 py-3 text-brand-moss">{{ $item->areaLabel() ?? '—' }}</td>
                                        <td class="px-3 py-3">
                                            @if ($item->is_published)
                                                <span class="inline-flex rounded-full bg-brand-sage/15 px-2 py-0.5 text-xs font-medium text-brand-forest">{{ __('Live') }}</span>
                                            @else
                                                <span class="inline-flex rounded-full bg-brand-sand/80 px-2 py-0.5 text-xs font-medium text-brand-moss">{{ __('Draft') }}</span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-3">
                                            <div class="inline-flex gap-1">
                                                <button type="button" wire:click="moveItem('{{ $item->id }}', 'up')" class="rounded border border-brand-ink/10 p-1 hover:bg-brand-sand/40" title="{{ __('Move up') }}">
                                                    <x-heroicon-o-chevron-up class="h-4 w-4 text-brand-moss" />
                                                </button>
                                                <button type="button" wire:click="moveItem('{{ $item->id }}', 'down')" class="rounded border border-brand-ink/10 p-1 hover:bg-brand-sand/40" title="{{ __('Move down') }}">
                                                    <x-heroicon-o-chevron-down class="h-4 w-4 text-brand-moss" />
                                                </button>
                                            </div>
                                        </td>
                                        <td class="px-3 py-3 text-end">
                                            <div class="inline-flex flex-wrap justify-end gap-2">
                                                <button type="button" wire:click="openEditItemModal('{{ $item->id }}')" class="text-sm font-medium text-brand-forest hover:underline">{{ __('Edit') }}</button>
                                                <button type="button" wire:click="requestDeleteItem('{{ $item->id }}')" class="text-sm font-medium text-brand-rust hover:underline">{{ __('Delete') }}</button>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="px-3 py-8 text-center text-sm text-brand-mist">{{ __('No items in this column.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>
            @endforeach
        </div>
    @else
        <div class="mb-6 flex flex-wrap items-end gap-3">
            <div class="min-w-[12rem] flex-1">
                <label for="suggestion-search" class="text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Search') }}</label>
                <input id="suggestion-search" type="search" wire:model.live.debounce.300ms="suggestionSearch" class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm shadow-sm" placeholder="{{ __('Title, email, or name…') }}" />
            </div>
            <div class="min-w-[10rem]">
                <label for="suggestion-status" class="text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Status') }}</label>
                <select id="suggestion-status" wire:model.live="suggestionStatusFilter" class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm shadow-sm">
                    <option value="">{{ __('All statuses') }}</option>
                    @foreach ($suggestionStatusLabels as $statusKey => $statusLabel)
                        <option value="{{ $statusKey }}">{{ __($statusLabel) }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="overflow-hidden rounded-xl border border-brand-ink/10 bg-white">
            <table class="min-w-full divide-y divide-brand-ink/10 text-left text-xs">
                <thead class="bg-brand-sand/40 text-brand-moss">
                    <tr>
                        <th class="px-3 py-2 font-medium">{{ __('When') }}</th>
                        <th class="px-3 py-2 font-medium">{{ __('Title') }}</th>
                        <th class="px-3 py-2 font-medium">{{ __('From') }}</th>
                        <th class="px-3 py-2 font-medium">{{ __('Status') }}</th>
                        <th class="px-3 py-2 font-medium text-end">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-ink/5">
                    @forelse ($suggestions as $suggestion)
                        <tr wire:key="admin-suggestion-{{ $suggestion->id }}">
                            <td class="px-3 py-2 whitespace-nowrap text-brand-moss">{{ $suggestion->created_at?->format('M j, Y g:i A') }}</td>
                            <td class="px-3 py-2 font-medium text-brand-ink">{{ $suggestion->title }}</td>
                            <td class="px-3 py-2 text-brand-moss">
                                {{ $suggestion->name ? $suggestion->name.' · ' : '' }}{{ $suggestion->email }}
                            </td>
                            <td class="px-3 py-2">
                                <span @class([
                                    'inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide',
                                    'bg-brand-rust/15 text-brand-rust' => $suggestion->status === \App\Models\RoadmapSuggestion::STATUS_NEW,
                                    'bg-brand-sage/15 text-brand-forest' => $suggestion->status === \App\Models\RoadmapSuggestion::STATUS_REVIEWED,
                                    'bg-brand-sand text-brand-moss' => $suggestion->status === \App\Models\RoadmapSuggestion::STATUS_DECLINED,
                                ])>{{ $suggestion->statusLabel() }}</span>
                            </td>
                            <td class="px-3 py-2 text-end">
                                <div class="inline-flex flex-wrap justify-end gap-2">
                                    <button type="button" wire:click="openSuggestion('{{ $suggestion->id }}')" class="font-medium text-brand-forest hover:underline">{{ __('View') }}</button>
                                    <button type="button" wire:click="openPromoteSuggestionModal('{{ $suggestion->id }}')" class="font-medium text-brand-forest hover:underline">{{ __('Promote') }}</button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-3 py-8 text-center text-sm text-brand-mist">{{ __('No suggestions yet.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($suggestions && $suggestions->hasPages())
            <div class="mt-4">{{ $suggestions->links() }}</div>
        @endif
    @endif

    @if ($showItemModal)
        @teleport('body')
        <div
            class="fixed inset-0 isolate z-[100] overflow-y-auto"
            role="dialog"
            aria-modal="true"
            aria-labelledby="roadmap-item-modal-title"
            x-data="{
                close() {
                    document.body.classList.remove('overflow-y-hidden')
                    $wire.closeItemModal()
                },
            }"
            x-init="
                document.body.classList.add('overflow-y-hidden');
                return () => document.body.classList.remove('overflow-y-hidden')
            "
            x-on:keydown.escape.window="close()"
        >
            <div class="fixed inset-0 z-0 bg-brand-ink/50 backdrop-blur-sm" x-on:click="close()"></div>
            <div class="relative z-10 flex min-h-full items-center justify-center px-4 py-10 sm:px-6">
                <x-dialog-shell :title="$editingItemId ? __('Edit roadmap item') : __('Add roadmap item')" title-id="roadmap-item-modal-title" max-width="lg">
                    <form wire:submit="saveItem" class="space-y-4">
                        <div>
                            <x-input-label for="item_title" :value="__('Title')" />
                            <x-text-input id="item_title" wire:model="itemTitle" type="text" class="w-full" required />
                            <x-input-error :messages="$errors->get('itemTitle')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="item_summary" :value="__('Summary (optional)')" />
                            <x-text-input id="item_summary" wire:model="itemSummary" type="text" class="w-full" />
                            <x-input-error :messages="$errors->get('itemSummary')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="item_description" :value="__('Description (optional)')" />
                            <textarea id="item_description" wire:model="itemDescription" rows="4" class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm shadow-sm"></textarea>
                            <x-input-error :messages="$errors->get('itemDescription')" class="mt-2" />
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <x-input-label for="item_status" :value="__('Status')" />
                                <select id="item_status" wire:model="itemStatus" class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm shadow-sm">
                                    @foreach ($statusLabels as $statusKey => $statusLabel)
                                        <option value="{{ $statusKey }}">{{ __($statusLabel) }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('itemStatus')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="item_area" :value="__('Area (optional)')" />
                                <select id="item_area" wire:model="itemArea" class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm shadow-sm">
                                    <option value="">{{ __('None') }}</option>
                                    @foreach ($areaLabels as $areaKey => $areaLabel)
                                        <option value="{{ $areaKey }}">{{ __($areaLabel) }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('itemArea')" class="mt-2" />
                            </div>
                        </div>
                        <label class="inline-flex items-center gap-2 text-sm text-brand-ink">
                            <input type="checkbox" wire:model="itemIsPublished" class="rounded border-brand-ink/30 text-brand-sage focus:ring-brand-sage" />
                            {{ __('Publish on public roadmap') }}
                        </label>
                    </form>

                    <x-slot name="footer">
                        <x-secondary-button type="button" x-on:click="close()">{{ __('Cancel') }}</x-secondary-button>
                        <x-primary-button type="button" wire:click="saveItem" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="saveItem">{{ __('Save item') }}</span>
                            <span wire:loading wire:target="saveItem">{{ __('Saving…') }}</span>
                        </x-primary-button>
                    </x-slot>
                </x-dialog-shell>
            </div>
        </div>
        @endteleport
    @endif

    @if ($viewingSuggestion)
        @teleport('body')
        <div
            class="fixed inset-0 isolate z-[100] overflow-y-auto"
            role="dialog"
            aria-modal="true"
            aria-labelledby="roadmap-suggestion-modal-title"
            x-data="{
                close() {
                    document.body.classList.remove('overflow-y-hidden')
                    $wire.closeSuggestion()
                },
            }"
            x-init="
                document.body.classList.add('overflow-y-hidden');
                return () => document.body.classList.remove('overflow-y-hidden')
            "
            x-on:keydown.escape.window="close()"
        >
            <div class="fixed inset-0 z-0 bg-brand-ink/50 backdrop-blur-sm" x-on:click="close()"></div>
            <div class="relative z-10 flex min-h-full items-center justify-center px-4 py-10 sm:px-6">
                <x-dialog-shell :title="__('Suggestion details')" title-id="roadmap-suggestion-modal-title" max-width="lg">
                    <div class="space-y-4 text-sm">
                        <dl class="divide-y divide-brand-ink/8 rounded-xl border border-brand-ink/10 bg-brand-sand/15">
                            <div class="flex flex-wrap gap-x-3 gap-y-1 px-4 py-2.5">
                                <dt class="w-28 shrink-0 text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Title') }}</dt>
                                <dd class="min-w-0 flex-1 text-brand-ink">{{ $viewingSuggestion->title }}</dd>
                            </div>
                            <div class="flex flex-wrap gap-x-3 gap-y-1 px-4 py-2.5">
                                <dt class="w-28 shrink-0 text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('From') }}</dt>
                                <dd class="min-w-0 flex-1 text-brand-ink">{{ $viewingSuggestion->name ?? '—' }} · {{ $viewingSuggestion->email }}</dd>
                            </div>
                            <div class="flex flex-wrap gap-x-3 gap-y-1 px-4 py-2.5">
                                <dt class="w-28 shrink-0 text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Status') }}</dt>
                                <dd class="min-w-0 flex-1 text-brand-ink">{{ $viewingSuggestion->statusLabel() }}</dd>
                            </div>
                        </dl>
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Description') }}</p>
                            <p class="mt-2 whitespace-pre-wrap leading-relaxed text-brand-moss">{{ $viewingSuggestion->description }}</p>
                        </div>
                        <div>
                            <x-input-label for="suggestion_admin_notes" :value="__('Admin notes (private)')" />
                            <textarea id="suggestion_admin_notes" wire:model="suggestionAdminNotes" rows="3" class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm shadow-sm"></textarea>
                            <x-input-error :messages="$errors->get('suggestionAdminNotes')" class="mt-2" />
                        </div>
                    </div>

                    <x-slot name="footer">
                        <x-secondary-button type="button" x-on:click="close()">{{ __('Close') }}</x-secondary-button>
                        <x-secondary-button type="button" wire:click="markSuggestionDeclined('{{ $viewingSuggestion->id }}')">{{ __('Decline') }}</x-secondary-button>
                        <x-secondary-button type="button" wire:click="markSuggestionReviewed('{{ $viewingSuggestion->id }}')">{{ __('Mark reviewed') }}</x-secondary-button>
                        <x-primary-button type="button" wire:click="saveSuggestionNotes" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="saveSuggestionNotes">{{ __('Save notes') }}</span>
                            <span wire:loading wire:target="saveSuggestionNotes">{{ __('Saving…') }}</span>
                        </x-primary-button>
                    </x-slot>
                </x-dialog-shell>
            </div>
        </div>
        @endteleport
    @endif

    @include('livewire.partials.confirm-action-modal')
</div>
