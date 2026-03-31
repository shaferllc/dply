@props([
    'catalog' => [],
    'orgHasPro' => false,
    'twoColumns' => true,
])

@php
    $card = 'rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden';
    $keys = array_keys($catalog);
    $half = (int) ceil(count($keys) / 2);
    $columns = $twoColumns ? array_chunk($keys, $half, true) : [$keys];
@endphp

<div class="space-y-4">
    <p class="text-sm text-brand-moss max-w-3xl">
        {{ __('Enable or disable specific insight types. Disabled insights will not be checked on the next run. Existing open findings stay until resolved or removed.') }}
    </p>

    <div class="grid gap-6 {{ $twoColumns ? 'lg:grid-cols-2' : '' }}">
        @foreach ($columns as $colKeys)
            <div class="space-y-4">
                @foreach ($colKeys as $key)
                    @php
                        $def = $catalog[$key] ?? null;
                    @endphp
                    @continue(! $def)
                    @php
                        $requiresPro = (bool) ($def['requires_pro'] ?? false);
                        $locked = $requiresPro && ! $orgHasPro;
                        $paramSpec = $def['parameters'] ?? [];
                    @endphp
                    <div class="{{ $card }} p-4 flex gap-3 items-start">
                        <input
                            type="checkbox"
                            id="in-{{ $key }}"
                            wire:model.live="enabled_map.{{ $key }}"
                            @disabled($locked)
                            class="mt-1 rounded border-brand-ink/20 text-brand-forest focus:ring-brand-sage"
                        />
                        <div class="min-w-0 flex-1">
                            <label for="in-{{ $key }}" class="flex flex-wrap items-center gap-2">
                                <span class="font-semibold text-brand-ink">{{ __($def['label'] ?? $key) }}</span>
                                @if ($requiresPro)
                                    <span class="rounded-md bg-brand-ink/90 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-cream">Pro</span>
                                @endif
                            </label>
                            <p class="mt-1 text-sm text-brand-moss">{{ __($def['description'] ?? '') }}</p>
                            @if ($locked)
                                <p class="mt-2 text-xs text-brand-mist">{{ __('Upgrade to Pro to enable this check.') }}</p>
                            @endif
                            @if (! $locked && $key === 'composer_vulnerabilities' && isset($paramSpec['severity']))
                                <div class="mt-3">
                                    <label class="text-xs font-medium text-brand-moss">{{ __($paramSpec['severity']['label'] ?? 'Severities') }}</label>
                                    <select
                                        wire:model.live="parameters.composer_vulnerabilities.severity"
                                        class="mt-1 block w-full max-w-xs rounded-lg border border-brand-ink/15 bg-white text-sm text-brand-ink shadow-sm"
                                    >
                                        @foreach ($paramSpec['severity']['options'] ?? [] as $opt)
                                            <option value="{{ $opt }}">{{ $opt === 'all' ? __('all') : ucfirst($opt) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>
</div>
