{{-- An on-demand read is queued SSH work: poll until its answer is cached,
     and after 90 seconds stop and say so rather than spin forever. --}}
@if ($this->readIsSlow($kind))
    <p class="px-4 py-5 text-center text-xs text-brand-moss sm:px-5">
        {{ __('No answer from the server yet — the read is queued behind other work, or the server isn’t responding. Use the button above to try again.') }}
    </p>
@else
    <div wire:poll.2s class="flex items-center justify-center gap-2 px-4 py-5 text-xs text-brand-moss sm:px-5">
        <x-spinner size="sm" /> {{ $label }}
    </div>
@endif
