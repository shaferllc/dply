{{--
    Env-var value control, shared by every place the editor edits a value.

    Driven by SiteEnvFieldHints::hint():
      - bool → true/false dropdown
      - enum → editable combobox: known values are *suggested* via <datalist>,
        but any value can still be typed. These keys (FILESYSTEM_DISK, MAIL_MAILER,
        QUEUE_CONNECTION, …) are NOT closed sets — custom disks/queues/drivers are
        valid — so the list must never block a free-text value.
      - text → plain (password-masked) input, with a Show/Hide toggle in the label.

    Expects: $hint (['type'=>..,'options'=>[..]]), $model (wire:model target string),
    $id (input element id). The text branch reads `showValue` from the surrounding
    Alpine scope, same as before.
--}}
@php($vInputClass = 'block w-full rounded-xl border border-brand-ink/15 bg-brand-cream/50 px-3 py-2 font-mono text-sm text-brand-ink')
@if (($hint['type'] ?? 'text') === 'bool')
    <select id="{{ $id }}" wire:model="{{ $model }}" class="{{ $vInputClass }}">
        @foreach ($hint['options'] as $opt)
            <option value="{{ $opt }}">{{ $opt }}</option>
        @endforeach
    </select>
@elseif (($hint['type'] ?? 'text') === 'enum')
    <input id="{{ $id }}" list="{{ $id }}__list" wire:model="{{ $model }}" autocomplete="off" spellcheck="false" class="{{ $vInputClass }}" />
    <datalist id="{{ $id }}__list">
        @foreach ($hint['options'] as $opt)
            <option value="{{ $opt }}"></option>
        @endforeach
    </datalist>
@else
    <input id="{{ $id }}" wire:model="{{ $model }}" x-bind:type="showValue ? 'text' : 'password'" autocomplete="off" spellcheck="false" class="{{ $vInputClass }}" />
@endif
