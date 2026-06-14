@php
    $impersonator = app(\App\Support\Impersonation\Impersonator::class);
@endphp

@if ($impersonator->isImpersonating() && auth()->check())
    <div class="sticky top-0 z-[60] border-b border-amber-300 bg-amber-400 text-amber-950 shadow-sm">
        <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3 px-4 py-2 sm:px-6 lg:px-8">
            <div class="flex min-w-0 items-center gap-2 text-sm">
                <svg class="h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.94 6.94a.75.75 0 11-1.061-1.061 3 3 0 112.871 5.026v.345a.75.75 0 01-1.5 0v-.5c0-.72.57-1.172 1.081-1.287A1.5 1.5 0 108.94 6.94zM10 15a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" />
                </svg>
                <span class="truncate">
                    {{ __('You are viewing as') }}
                    <span class="font-semibold">{{ auth()->user()->name }}</span>
                    <span class="hidden opacity-80 sm:inline">({{ auth()->user()->email }})</span>
                </span>
            </div>
            <form method="POST" action="{{ route('impersonate.leave') }}" class="shrink-0">
                @csrf
                <button
                    type="submit"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-amber-950 px-3 py-1.5 text-xs font-semibold text-amber-50 shadow-sm hover:bg-amber-900 focus:outline-none focus:ring-2 focus:ring-amber-950/40"
                >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M3 4.25A2.25 2.25 0 015.25 2h5.5A2.25 2.25 0 0113 4.25v2a.75.75 0 01-1.5 0v-2a.75.75 0 00-.75-.75h-5.5a.75.75 0 00-.75.75v11.5c0 .414.336.75.75.75h5.5a.75.75 0 00.75-.75v-2a.75.75 0 011.5 0v2A2.25 2.25 0 0110.75 18h-5.5A2.25 2.25 0 013 15.75V4.25z" clip-rule="evenodd" />
                        <path fill-rule="evenodd" d="M19 10a.75.75 0 00-.75-.75H8.704l1.048-.943a.75.75 0 10-1.004-1.114l-2.5 2.25a.75.75 0 000 1.114l2.5 2.25a.75.75 0 101.004-1.114l-1.048-.943h9.546A.75.75 0 0019 10z" clip-rule="evenodd" />
                    </svg>
                    {{ __('Stop impersonating') }}
                </button>
            </form>
        </div>
    </div>
@endif
