@php
    $refKind = $git_ref_kind ?? 'branch';
    $refLabel = match ($refKind) {
        'tag' => __('Tag'),
        'commit' => __('Commit'),
        default => __('Branch'),
    };
@endphp

<div>
    <x-input-label :value="__('Ref')" required />
    <div class="mt-1.5 flex flex-wrap items-center gap-2">
        <div class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 shadow-sm">
            @if ($refKind === 'tag')
                <x-heroicon-o-tag class="h-4 w-4 text-brand-mist" aria-hidden="true" />
            @elseif ($refKind === 'commit')
                <x-heroicon-o-code-bracket-square class="h-4 w-4 text-brand-mist" aria-hidden="true" />
            @else
                <x-heroicon-o-arrow-trending-up class="h-4 w-4 text-brand-mist" aria-hidden="true" />
            @endif
            <span class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ $refLabel }}</span>
            <span class="font-mono text-sm text-brand-ink">{{ $git_branch !== '' ? $git_branch : __('—') }}</span>
        </div>
        <button
            type="button"
            wire:click="{{ $refPickerOpen ? 'closeRefPicker' : 'openRefPicker' }}"
            wire:loading.attr="disabled"
            wire:target="openRefPicker,setRefPickerTab,updatedRefPickerSearch"
            class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:cursor-progress disabled:opacity-60"
            title="{{ __('Browse branches, tags, and commits from the repo.') }}"
        >
            <span wire:loading.remove wire:target="openRefPicker" class="inline-flex items-center gap-1.5">
                <x-heroicon-o-magnifying-glass class="h-4 w-4" aria-hidden="true" />
                {{ $refPickerOpen ? __('Hide picker') : __('Change') }}
            </span>
            <span wire:loading wire:target="openRefPicker" class="inline-flex items-center gap-1.5">
                <x-spinner size="sm" />
                {{ __('Loading…') }}
            </span>
        </button>
    </div>
    <x-input-error :messages="$errors->get('git_branch')" class="mt-2" />

    @if ($refPickerOpen)
        <div class="mt-3 overflow-hidden rounded-xl border border-brand-ink/10 bg-white shadow-sm">
            <div class="flex items-center justify-between gap-2 border-b border-brand-ink/10 px-3 py-2">
                <div class="inline-flex rounded-md border border-brand-ink/15 bg-brand-sand/20 p-0.5 text-xs font-semibold">
                    @foreach ([
                        'branches' => __('Branches'),
                        'tags' => __('Tags'),
                        'commits' => __('Commits'),
                    ] as $tabKey => $tabLabel)
                        <button
                            type="button"
                            wire:click="setRefPickerTab('{{ $tabKey }}')"
                            class="rounded-md px-2.5 py-1 transition {{ $refPickerTab === $tabKey ? 'bg-brand-ink text-brand-cream shadow-sm' : 'text-brand-moss hover:text-brand-ink' }}"
                        >
                            {{ $tabLabel }}
                        </button>
                    @endforeach
                </div>
                <button type="button" wire:click="closeRefPicker" class="rounded-md p-1 text-brand-mist hover:bg-brand-sand/40 hover:text-brand-ink" title="{{ __('Close') }}">
                    <x-heroicon-o-x-mark class="h-4 w-4" aria-hidden="true" />
                </button>
            </div>
            <div class="border-b border-brand-ink/10 px-3 py-2">
                <div class="relative">
                    <span class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-2.5 text-brand-mist" aria-hidden="true">
                        <x-heroicon-o-magnifying-glass class="h-4 w-4" />
                    </span>
                    <x-text-input
                        wire:model.live.debounce.300ms="refPickerSearch"
                        type="text"
                        class="block w-full ps-8 text-xs"
                        placeholder="{{ __('Filter…') }}"
                    />
                </div>
            </div>
            <div class="max-h-64 overflow-y-auto">
                @if ($refPickerLoading)
                    <div class="flex items-center justify-center gap-2 px-3 py-6 text-xs text-brand-moss">
                        <x-spinner size="sm" variant="ink" />
                        {{ __('Loading…') }}
                    </div>
                @elseif ($refPickerError !== null)
                    <div class="px-3 py-4 text-xs text-rose-700">{{ $refPickerError }}</div>
                @elseif ($refPickerResults === [])
                    <div class="px-3 py-6 text-center text-xs text-brand-mist">{{ __('No matches.') }}</div>
                @else
                    <ul class="divide-y divide-brand-ink/8">
                        @php
                            $kindForRow = match ($refPickerTab) {
                                'tags' => 'tag',
                                'commits' => 'commit',
                                default => 'branch',
                            };
                        @endphp
                        @foreach ($refPickerResults as $row)
                            @php
                                $pickValue = $kindForRow === 'commit' ? (string) ($row['sha'] ?? '') : (string) ($row['label'] ?? '');
                                $isSelected = $kindForRow === 'commit'
                                    ? str_starts_with(strtolower((string) $git_branch), strtolower(substr((string) ($row['sha'] ?? ''), 0, 7)))
                                    : $git_branch === ($row['label'] ?? '') && ($git_ref_kind ?? 'branch') === $kindForRow;
                            @endphp
                            <li>
                                <button
                                    type="button"
                                    wire:click="selectRefPickerValue(@js($pickValue), @js($kindForRow))"
                                    class="flex w-full items-start gap-3 px-3 py-2.5 text-left transition hover:bg-brand-sand/20 {{ $isSelected ? 'bg-brand-sage/10' : '' }}"
                                >
                                    <span class="min-w-0 flex-1">
                                        <span class="font-mono text-sm font-semibold text-brand-ink">{{ $row['label'] }}</span>
                                        @if (! empty($row['meta']))
                                            <span class="mt-0.5 block truncate text-xs text-brand-moss">{{ $row['meta'] }}</span>
                                        @endif
                                    </span>
                                    @if ($isSelected)
                                        <x-heroicon-m-check class="mt-0.5 h-4 w-4 shrink-0 text-brand-forest" aria-hidden="true" />
                                    @endif
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
            <div class="border-t border-brand-ink/10 bg-brand-sand/20 px-3 py-2">
                <div class="flex flex-wrap items-end gap-2">
                    <div class="min-w-0 flex-1">
                        <label for="serverless-ref-manual" class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Or type a ref') }}</label>
                        <x-text-input
                            id="serverless-ref-manual"
                            type="text"
                            wire:model="git_branch"
                            class="mt-1 block w-full font-mono text-xs"
                            placeholder="main"
                            autocomplete="off"
                        />
                    </div>
                    <select wire:model="git_ref_kind" class="rounded-lg border border-brand-ink/15 bg-white px-2 py-2 text-xs font-semibold text-brand-ink shadow-sm">
                        <option value="branch">{{ __('Branch') }}</option>
                        <option value="tag">{{ __('Tag') }}</option>
                        <option value="commit">{{ __('Commit') }}</option>
                    </select>
                </div>
            </div>
        </div>
    @endif
</div>
