@php
    $linkedSecretRows = method_exists($this, 'linkedOrganizationSecretRows')
        ? $this->linkedOrganizationSecretRows()
        : [];
    $canLinkSecrets = method_exists($this, 'openLinkOrganizationSecretModal');
    $secretsCard = $secretsCard ?? 'border-b border-brand-ink/10';
    $secretGroups = collect($linkedSecretRows)->groupBy(
        static fn (array $row): string => filled($row['notes'] ?? null) ? (string) $row['notes'] : '',
    );
@endphp

@if ($canLinkSecrets)
    <section class="{{ $secretsCard }}" x-data="{ open: true }">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-2.5 sm:px-6">
            <button
                type="button"
                class="flex min-w-0 flex-wrap items-center gap-2 text-left"
                x-on:click="open = ! open"
                :aria-expanded="open"
            >
                <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist transition-transform" x-bind:class="open && 'rotate-90'" />
                <x-heroicon-o-lock-closed class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                <h2 class="text-sm font-semibold text-brand-ink">{{ __('Linked secrets') }}</h2>
                <span class="inline-flex items-center rounded-full bg-white px-2 py-0.5 text-2xs font-semibold tabular-nums text-brand-moss ring-1 ring-brand-ink/10">
                    {{ trans_choice('{0} none|{1} :count linked|[2,*] :count linked', count($linkedSecretRows), ['count' => count($linkedSecretRows)]) }}
                </span>
            </button>
            @can('update', $site)
                <button
                    type="button"
                    wire:click="openLinkOrganizationSecretModal"
                    x-on:click="$dispatch('open-modal', 'link-organization-secret-modal')"
                    class="dply-btn dply-btn-sm dply-btn-outline"
                >
                    {{ __('Paste or link secrets') }}
                </button>
            @endcan
        </div>

        <div x-show="open" x-cloak>
            @if ($linkedSecretRows === [])
                <p class="px-5 py-4 text-sm text-brand-moss sm:px-6">{{ __('No secrets linked. Paste a key here or pick one from the org vault — it injects on the next deploy and cannot be read back.') }}</p>
            @else
                @foreach ($secretGroups as $note => $rows)
                    <div
                        class="{{ ! $loop->first ? 'border-t border-brand-ink/10' : '' }}"
                        wire:key="secret-group-{{ md5((string) $note) }}"
                        x-data="{ expanded: true }"
                    >
                        @if ($secretGroups->count() > 1)
                            <button
                                type="button"
                                x-on:click="expanded = ! expanded"
                                class="flex w-full items-center gap-2 bg-brand-sand/10 px-5 py-1.5 text-left sm:px-6"
                            >
                                <x-heroicon-m-chevron-right class="h-3.5 w-3.5 shrink-0 text-brand-mist transition-transform" x-bind:class="expanded && 'rotate-90'" />
                                <span class="text-xs font-semibold text-brand-ink">{{ $note !== '' ? $note : __('No note') }}</span>
                                <span class="text-2xs font-semibold tabular-nums text-brand-mist">{{ $rows->count() }}</span>
                            </button>
                        @endif
                        <ul class="divide-y divide-brand-ink/8" @if ($secretGroups->count() > 1) x-show="expanded" x-cloak @endif>
                            @foreach ($rows as $row)
                                <li class="px-5 py-1 transition-colors hover:bg-brand-sand/15 sm:px-6" wire:key="linked-secret-{{ $row['id'] }}">
                                    <div class="flex items-center gap-3">
                                        <div class="flex min-w-0 flex-1 items-center gap-2.5">
                                            <div class="flex min-w-0 shrink-0 items-center gap-1 sm:w-64">
                                                <span class="truncate font-mono text-xs font-semibold text-brand-ink" title="{{ $row['key'] }}">{{ $row['key'] }}</span>
                                                @if ($row['overrides_site'])
                                                    <span
                                                        class="inline-flex shrink-0 items-center rounded bg-amber-50 p-0.5 text-amber-900 ring-1 ring-inset ring-amber-200/70"
                                                        title="{{ __('Override — this secret wins over the same key in the site .env.') }}"
                                                    >
                                                        <x-heroicon-m-link class="h-3 w-3" />
                                                        <span class="sr-only">{{ __('Override') }}</span>
                                                    </span>
                                                @endif
                                                @if ($row['binding_owned'])
                                                    <span
                                                        class="inline-flex shrink-0 items-center rounded bg-rose-50 p-0.5 text-rose-800 ring-1 ring-inset ring-rose-200/70"
                                                        title="{{ __('A connected resource already owns this key. The binding wins unless you override it in the site .env.') }}"
                                                    >
                                                        <x-heroicon-m-squares-2x2 class="h-3 w-3" />
                                                        <span class="sr-only">{{ __('Binding owns this key') }}</span>
                                                    </span>
                                                @endif
                                            </div>
                                            <p class="min-w-0 flex-1 truncate font-mono text-xs text-brand-mist">{{ str_repeat('•', 8) }}</p>
                                        </div>
                                        @can('update', $site)
                                            <div class="flex shrink-0 items-center gap-1">
                                                <button
                                                    type="button"
                                                    class="rounded p-1 text-brand-moss transition-colors hover:bg-rose-50 hover:text-rose-700"
                                                    title="{{ __('Unlink :key', ['key' => $row['key']]) }}"
                                                    wire:click="openConfirmActionModal('unlinkOrganizationSecret', @js([$row['id']]), @js(__('Unlink secret')), @js(__('Unlink :key from this site? The key drops on the next deploy. The org secret is kept.', ['key' => $row['key']])), @js(__('Unlink')), true)"
                                                >
                                                    <x-heroicon-o-x-mark class="h-4 w-4" />
                                                    <span class="sr-only">{{ __('Unlink :key', ['key' => $row['key']]) }}</span>
                                                </button>
                                            </div>
                                        @endcan
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            @endif
        </div>
    </section>

    @include('livewire.sites.partials.link-organization-secret-modal')
@endif
