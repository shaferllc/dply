{{-- Conditional poll: only fires while a queued engine job is in flight. The element
     disappears the moment all rows settle, so polling stops on its own. --}}
<div wire:poll.4s class="hidden" aria-hidden="true"></div>
