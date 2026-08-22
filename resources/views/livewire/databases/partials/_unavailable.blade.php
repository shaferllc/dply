{{-- Shown in place of a panel the backend cannot serve. Says which backend and
     why, so it reads as a product boundary rather than a failed load. --}}
<div class="rounded-2xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-600 shadow-sm">
    <p class="font-semibold text-slate-900">{{ $title }}</p>
    <p class="mx-auto mt-1 max-w-lg">{{ $reason }}</p>
</div>
