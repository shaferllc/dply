@php
    $card = 'rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden';
@endphp
@if (session('success') || $flash_success)
    <div class="rounded-2xl border border-emerald-200/80 bg-emerald-50/90 px-4 py-3 text-sm text-emerald-900">{{ $flash_success ?? session('success') }}</div>
@endif
@if (session('error') || $flash_error)
    <div class="rounded-2xl border border-amber-200/80 bg-amber-50/90 px-4 py-3 text-sm text-amber-900">{{ $flash_error ?? session('error') }}</div>
@endif
@if (isset($command_output) && $command_output)
    <div class="{{ $card }}">
        <div class="border-b border-brand-ink/10 px-5 py-3 text-sm font-medium text-brand-ink">{{ __('Command output') }}</div>
        <pre class="max-h-96 overflow-x-auto bg-brand-ink p-4 text-sm text-emerald-400/95">{{ $command_output }}</pre>
    </div>
@endif
@if (isset($command_error) && $command_error)
    <div class="rounded-2xl border border-red-200/80 bg-red-50/90 px-4 py-3 text-sm text-red-900">{{ $command_error }}</div>
@endif
