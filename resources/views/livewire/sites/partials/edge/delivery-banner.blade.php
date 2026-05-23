@if (! empty($edgeDeliveryBanner))
    @php
        $tone = $edgeDeliveryBanner['tone'] ?? 'amber';
        $bannerClass = match ($tone) {
            'emerald' => 'border-emerald-200 bg-emerald-50/80 text-emerald-950 dark:border-emerald-900/40 dark:bg-emerald-950/30 dark:text-emerald-200',
            default => 'border-amber-200 bg-amber-50/80 text-amber-950 dark:border-amber-900/40 dark:bg-amber-950/30 dark:text-amber-200',
        };
    @endphp
    <div class="rounded-2xl border px-4 py-3 text-sm {{ $bannerClass }}">
        <p class="font-semibold">{{ $edgeDeliveryBanner['title'] }}</p>
        <p class="mt-1 leading-relaxed opacity-90">{{ $edgeDeliveryBanner['message'] }}</p>
    </div>
@endif
