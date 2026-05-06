@props([
    'engine',
    'engineLabel',
    'row',
    'pattern',
    'keys',
    'loaded',
    'complete',
    'selected',
    'value',
    'valueError' => null,
    'error' => null,
    'replUnlocked' => false,
    'card' => 'dply-card overflow-hidden',
])

<div class="{{ $card }} p-6 sm:p-8" wire:key="cache-key-browser-{{ $engine }}">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <h3 class="text-lg font-semibold text-brand-ink">{{ __(':engine — key browser', ['engine' => $engineLabel]) }}</h3>
            <p class="mt-2 text-sm text-brand-moss">{{ __('SCAN-based key explorer. Walks the keyspace in pages without locking the engine the way KEYS * does.') }}</p>
        </div>
        <div class="flex shrink-0 flex-wrap gap-2 self-start whitespace-nowrap">
            @if ($loaded)
                <button type="button" wire:click="hideKeyBrowser" class="inline-flex items-center gap-2 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-sm font-medium text-brand-ink hover:bg-brand-sand/40">
                    <x-heroicon-o-eye-slash class="h-3.5 w-3.5" aria-hidden="true" />
                    {{ __('Hide') }}
                </button>
            @endif
        </div>
    </div>

    <x-explainer class="mt-4">
        <p>{{ __('Each page runs SCAN with a small COUNT for up to 5 iterations server-side, returning the keys it found. The cursor in the response lets the next "Load more" continue exactly where it left off. Under heavy write traffic SCAN can repeat keys across pages — the explorer dedupes them client-side.') }}</p>
        <p>
            {{ __('Pattern is the redis SCAN MATCH glob — ') }}
            <code>*</code> {{ __('for everything, ') }}<code>session:*</code> {{ __('for one prefix, ') }}<code>?ache</code> {{ __('for single-character wildcard. ') }}
            {{ __('Inspecting a key fetches TYPE + TTL + value (truncated at 8 KB).') }}
        </p>
        <p>{{ __('Deleting a key requires the unlock toggle in the Console sub-tab. Every DEL is recorded in the audit log.') }}</p>
    </x-explainer>

    <form wire:submit.prevent="searchKeyBrowser" class="mt-4 flex flex-wrap items-end gap-2">
        <div class="grow">
            <x-input-label for="keyBrowserPattern" :value="__('Pattern')" />
            <x-text-input
                id="keyBrowserPattern"
                wire:model="keyBrowserPattern"
                type="text"
                spellcheck="false"
                autocomplete="off"
                class="mt-1 block w-full font-mono text-sm"
                placeholder="*"
                wire:loading.attr="disabled"
                wire:target="searchKeyBrowser,loadKeyBrowserPage"
            />
        </div>
        <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="searchKeyBrowser">
            <span wire:loading.remove wire:target="searchKeyBrowser">{{ __('Search') }}</span>
            <span wire:loading wire:target="searchKeyBrowser">{{ __('Scanning…') }}</span>
        </x-primary-button>
    </form>

    @if ($error)
        <p class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs leading-relaxed text-rose-800">{{ $error }}</p>
    @endif

    @if ($loaded && empty($keys) && ! $error)
        <p class="mt-4 text-sm text-brand-moss">{{ __('No keys matched the pattern.') }}</p>
    @endif

    @if (! empty($keys))
        <div class="mt-4 overflow-x-auto rounded-xl border border-brand-ink/10">
            <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                <thead class="bg-brand-sand/40 text-left text-xs font-semibold uppercase tracking-wide text-brand-mist">
                    <tr>
                        <th class="px-4 py-3">{{ __('Key') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-ink/10 bg-white">
                    @foreach ($keys as $key)
                        <tr @class([
                            'bg-brand-sand/30' => $selected === $key,
                        ])>
                            <td class="px-4 py-2 font-mono text-xs text-brand-ink break-all">{{ $key }}</td>
                            <td class="px-4 py-2 text-right whitespace-nowrap">
                                <button
                                    type="button"
                                    wire:click="inspectKey('{{ addslashes($key) }}')"
                                    class="text-xs font-medium text-brand-forest hover:underline"
                                >{{ __('Inspect') }}</button>
                                @if ($replUnlocked)
                                    <span class="text-brand-mist mx-1">·</span>
                                    <button
                                        type="button"
                                        wire:click="openConfirmActionModal('deleteKey', ['{{ addslashes($key) }}'], @js(__('Delete key')), @js(__('Drop key :key from this engine. Cannot be undone.', ['key' => $key])), @js(__('Delete')), true)"
                                        class="text-xs font-medium text-rose-700 hover:underline"
                                    >{{ __('Delete') }}</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-xs text-brand-moss">
            <span>{{ __('Showing :count keys', ['count' => count($keys)]) }}</span>
            @if (! $complete)
                <button
                    type="button"
                    wire:click="loadKeyBrowserPage"
                    wire:loading.attr="disabled"
                    wire:target="loadKeyBrowserPage"
                    class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-2.5 py-1.5 font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50"
                >
                    <x-heroicon-o-arrow-down class="h-3 w-3" />
                    <span wire:loading.remove wire:target="loadKeyBrowserPage">{{ __('Load more') }}</span>
                    <span wire:loading wire:target="loadKeyBrowserPage">{{ __('Loading…') }}</span>
                </button>
            @else
                <span class="text-brand-mist">{{ __('Scan complete.') }}</span>
            @endif
        </div>
    @endif

    @if ($selected !== null)
        <div class="mt-4 rounded-xl border border-brand-ink/10 bg-brand-sand/20 p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Inspecting') }}</p>
                    <p class="mt-1 break-all font-mono text-sm text-brand-ink">{{ $selected }}</p>
                </div>
                <button
                    type="button"
                    wire:click="clearKeyInspection"
                    class="text-xs font-medium text-brand-moss hover:underline"
                >{{ __('Close') }}</button>
            </div>

            @if ($valueError)
                <p class="mt-3 rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs leading-relaxed text-rose-800">{{ $valueError }}</p>
            @elseif ($value !== null)
                <dl class="mt-3 grid gap-3 sm:grid-cols-3">
                    <div>
                        <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Type') }}</dt>
                        <dd class="mt-1 font-mono text-sm text-brand-ink">{{ $value['type'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('TTL (s)') }}</dt>
                        <dd class="mt-1 font-mono text-sm text-brand-ink">
                            @if ($value['ttl'] === -1)
                                <span class="text-brand-moss">{{ __('no expiry') }}</span>
                            @elseif ($value['ttl'] === -2)
                                <span class="text-rose-700">{{ __('expired') }}</span>
                            @else
                                {{ $value['ttl'] }}
                            @endif
                        </dd>
                    </div>
                    @if ($value['truncated'])
                        <div>
                            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Truncated') }}</dt>
                            <dd class="mt-1 text-xs text-amber-700">{{ __('Showing first 8 KB only') }}</dd>
                        </div>
                    @endif
                </dl>

                <div class="mt-4">
                    <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Value') }}</dt>
                    <dd class="mt-1">
                        @if (is_array($value['value']))
                            <ul class="space-y-0.5 rounded-lg bg-brand-ink/95 p-3 font-mono text-xs leading-relaxed text-emerald-100">
                                @foreach ($value['value'] as $item)
                                    <li class="break-all">{{ $item }}</li>
                                @endforeach
                            </ul>
                        @else
                            <pre class="whitespace-pre-wrap break-all rounded-lg bg-brand-ink/95 p-3 font-mono text-xs leading-relaxed text-emerald-100">{{ $value['value'] }}</pre>
                        @endif
                    </dd>
                </div>
            @endif
        </div>
    @endif
</div>
