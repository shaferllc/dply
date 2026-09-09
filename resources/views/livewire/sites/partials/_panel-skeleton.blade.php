{{-- Shared lazy/loading placeholder for a network- or DB-backed panel. Used by
     the Repository sub-tabs and the Deployments hub tabs so a tab switch paints
     an instant skeleton (client-side via wire:loading) instead of a spinner or
     frozen content, while the real panel streams in.

     Strip-shaped to match the merged dply-card chrome (no nested floating card). --}}
<section class="border-b border-brand-ink/10" aria-busy="true" aria-live="polite">
    <span class="sr-only">{{ __('Loading…') }}</span>
    <div class="flex items-start gap-2 border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-3.5 sm:px-6" aria-hidden="true">
        <span class="mt-0.5 h-4 w-4 shrink-0 animate-pulse rounded bg-brand-ink/10"></span>
        <div class="min-w-0 flex-1 space-y-1.5">
            <span class="block h-3.5 w-48 max-w-full animate-pulse rounded bg-brand-ink/15"></span>
            <span class="block h-2.5 w-64 max-w-full animate-pulse rounded bg-brand-ink/10"></span>
        </div>
    </div>
    <div class="divide-y divide-brand-ink/10 px-5 py-1 sm:px-6" aria-hidden="true">
        @for ($i = 0; $i < 5; $i++)
            <div class="flex items-center justify-between gap-4 py-2.5">
                <div class="min-w-0 flex-1 space-y-2">
                    <span class="block h-2.5 w-16 animate-pulse rounded bg-brand-ink/10"></span>
                    <span class="block h-3 animate-pulse rounded bg-brand-ink/15" style="width: {{ [70, 55, 80, 45, 62][$i] }}%"></span>
                </div>
                <span class="h-6 w-14 shrink-0 animate-pulse rounded-md bg-brand-ink/10"></span>
            </div>
        @endfor
    </div>
</section>
