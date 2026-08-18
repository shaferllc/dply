{{--
    Attach-existing resource picker shared by the database / redis / broadcasting
    binding modals. Splits options into "This server", "Private-network peers",
    "Dedicated cache servers", and "Managed Redis" optgroups when present.

    Props:
      targets     list<array{id,label,group?,consumers?}> from SiteBindingManager::attachableTargets()
      model       wire model path (e.g. "bindingForm.target_id")
      selected    current value (for the Alpine warning's initial state)
      id          <select> id (matched by the sibling <x-input-label for=...>)
      placeholder first, empty option label
      live        true → wire:model.live (needed when the parent recomputes on change)
--}}
@props([
    'targets' => [],
    'model' => 'bindingForm.target_id',
    'selected' => '',
    'id' => 'binding_target',
    'placeholder' => null,
    'live' => false,
])

@php
    $targets = collect($targets);
    $local = $targets->filter(fn ($t) => ($t['group'] ?? '') === 'local')->values();
    $peers = $targets->filter(fn ($t) => ($t['group'] ?? '') === 'peer')->values();
    $dedicated = $targets->filter(fn ($t) => ($t['group'] ?? '') === 'dedicated')->values();
    $managed = $targets->filter(fn ($t) => ($t['group'] ?? '') === 'managed')->values();
    $rest = $targets->reject(fn ($t) => in_array($t['group'] ?? '', ['local', 'peer', 'dedicated', 'managed'], true))->values();
    $hasGroups = $local->isNotEmpty() || $peers->isNotEmpty() || $dedicated->isNotEmpty() || $managed->isNotEmpty();
    $consumerMap = $targets->mapWithKeys(fn ($t) => [(string) $t['id'] => (int) ($t['consumers'] ?? 0)])->all();
    $placeholder ??= __('Choose a service…');
@endphp

<div x-data="{ consumers: @js($consumerMap), sel: @js((string) $selected) }">
    <select id="{{ $id }}" {{ $live ? 'wire:model.live' : 'wire:model' }}="{{ $model }}" @change="sel = $event.target.value" class="dply-input">
        <option value="">{{ $placeholder }}</option>
        @if ($hasGroups)
            @if ($local->isNotEmpty())
                <optgroup label="{{ __('This server') }}">
                    @foreach ($local as $t)
                        <option value="{{ $t['id'] }}">{{ $t['label'] }}</option>
                    @endforeach
                </optgroup>
            @endif
            @if ($peers->isNotEmpty())
                <optgroup label="{{ __('Private-network peers') }}">
                    @foreach ($peers as $t)
                        <option value="{{ $t['id'] }}">{{ $t['label'] }}</option>
                    @endforeach
                </optgroup>
            @endif
            @if ($dedicated->isNotEmpty())
                <optgroup label="{{ __('Dedicated cache servers') }}">
                    @foreach ($dedicated as $t)
                        <option value="{{ $t['id'] }}">{{ $t['label'] }}</option>
                    @endforeach
                </optgroup>
            @endif
            @if ($managed->isNotEmpty())
                <optgroup label="{{ __('Managed Redis') }}">
                    @foreach ($managed as $t)
                        <option value="{{ $t['id'] }}">{{ $t['label'] }}</option>
                    @endforeach
                </optgroup>
            @endif
            @foreach ($rest as $t)
                <option value="{{ $t['id'] }}">{{ $t['label'] }}</option>
            @endforeach
        @else
            @foreach ($targets as $t)
                <option value="{{ $t['id'] }}">{{ $t['label'] }}</option>
            @endforeach
        @endif
    </select>

    <div
        x-show="(consumers[sel] || 0) > 0"
        style="display: none"
        class="mt-2 flex items-start gap-1.5 rounded-lg border border-amber-200 bg-amber-50 px-2.5 py-1.5 text-xs leading-snug text-amber-900"
    >
        <x-heroicon-m-exclamation-triangle class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
        <span x-text="`${consumers[sel]} other app${consumers[sel] == 1 ? '' : 's'} already use this resource — they share its data/keyspace. Set a prefix or a separate database/channel namespace to keep them isolated.`"></span>
    </div>
</div>
