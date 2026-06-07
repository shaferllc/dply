{{--
    Lazy-load skeleton for server workspace tabs. Rendered by
    App\Livewire\Servers\Concerns\RendersWorkspacePlaceholder::placeholder()
    while a #[Lazy] workspace component hydrates. It re-renders the same
    <x-server-workspace-layout> chrome (header + active tab) as the real
    page so switching tabs via wire:navigate shows the destination tab
    highlighted instantly with a pulsing body, then fills in.

    Receives: $server, $active (tab key | null), $title (string | null).
--}}
<x-server-workspace-layout :server="$server" :active="$active" :title="$title">
    <div class="space-y-6" aria-busy="true" aria-live="polite">
        <span class="sr-only">{{ __('Loading…') }}</span>

        @foreach (range(1, 3) as $skeletonCard)
            <div class="dply-card overflow-hidden" aria-hidden="true">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <div class="h-10 w-10 shrink-0 animate-pulse rounded-xl bg-brand-ink/10"></div>
                    <div class="min-w-0 flex-1 space-y-2">
                        <div class="h-3 w-24 animate-pulse rounded bg-brand-ink/10"></div>
                        <div class="h-4 w-48 animate-pulse rounded bg-brand-ink/10"></div>
                    </div>
                </div>
                <div class="space-y-3 p-6 sm:p-7">
                    <div class="h-4 w-full max-w-2xl animate-pulse rounded bg-brand-ink/10"></div>
                    <div class="h-4 w-2/3 max-w-xl animate-pulse rounded bg-brand-ink/10"></div>
                    <div class="h-4 w-1/2 max-w-md animate-pulse rounded bg-brand-ink/10"></div>
                </div>
            </div>
        @endforeach
    </div>
</x-server-workspace-layout>
