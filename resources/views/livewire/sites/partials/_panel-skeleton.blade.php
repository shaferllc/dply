{{-- Shared lazy/loading placeholder for a network- or DB-backed panel. Used by
     the Repository sub-tabs and the Deployments hub tabs so a tab switch paints
     an instant skeleton (client-side via wire:loading) instead of a spinner or
     frozen content, while the real panel streams in. --}}
<section class="space-y-6" aria-busy="true" aria-live="polite">
    <span class="sr-only">{{ __('Loading…') }}</span>
    <div class="dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <span class="h-10 w-10 shrink-0 animate-pulse rounded-2xl bg-brand-ink/10"></span>
            <div class="min-w-0 flex-1 space-y-2">
                <span class="block h-2.5 w-24 animate-pulse rounded bg-brand-ink/10"></span>
                <span class="block h-3.5 w-48 animate-pulse rounded bg-brand-ink/15"></span>
                <span class="block h-2.5 w-64 max-w-full animate-pulse rounded bg-brand-ink/10"></span>
            </div>
        </div>
        <div class="divide-y divide-brand-ink/10 px-6 py-2 sm:px-7">
            @for ($i = 0; $i < 4; $i++)
                <div class="flex items-center justify-between gap-4 py-3">
                    <div class="min-w-0 flex-1 space-y-2">
                        <span class="block h-2.5 w-16 animate-pulse rounded bg-brand-ink/10"></span>
                        <span class="block h-3 animate-pulse rounded bg-brand-ink/15" style="width: {{ [70, 55, 80, 45][$i] }}%"></span>
                    </div>
                    <span class="h-6 w-6 shrink-0 animate-pulse rounded-lg bg-brand-ink/10"></span>
                </div>
            @endfor
        </div>
    </div>
</section>
