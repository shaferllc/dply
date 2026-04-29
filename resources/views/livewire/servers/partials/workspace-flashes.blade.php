@php
    $card = 'dply-card overflow-hidden';
@endphp

@if (isset($command_output) && $command_output)
    <div class="{{ $card }}">
        <div class="border-b border-brand-ink/10 px-5 py-3 text-sm font-medium text-brand-ink">{{ __('Command output') }}</div>
        <pre class="max-h-96 overflow-x-auto bg-brand-ink p-4 text-sm text-emerald-400/95">{{ $command_output }}</pre>
    </div>
@endif
