@php
    $kindLabel = ($deployHookKinds ?? [])[$new_hook_kind] ?? $new_hook_kind;
    $anchorLabel = ($deployHookAnchors ?? [])[$new_hook_anchor] ?? $new_hook_anchor;
    $kindIcon = match ($new_hook_kind) {
        'webhook' => 'heroicon-o-globe-alt',
        'notification' => 'heroicon-o-bell-alert',
        default => 'heroicon-o-bolt',
    };
    $kindTone = match ($new_hook_kind) {
        'webhook' => 'border-violet-200/80 bg-violet-50 text-violet-900 ring-violet-200/50',
        'notification' => 'border-amber-200/80 bg-amber-50 text-amber-950 ring-amber-200/50',
        default => 'border-brand-sage/40 bg-brand-sage/15 text-brand-forest ring-brand-sage/30',
    };
    $afterStep = $new_hook_anchor === 'after_step' && filled($new_hook_anchor_step_id)
        ? collect($orderedSteps ?? [])->firstWhere('id', $new_hook_anchor_step_id)
        : null;
@endphp

<div class="sm:col-span-2 rounded-xl bg-brand-sand/30 px-4 py-3.5 ring-1 ring-brand-ink/8">
    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Placement') }}</p>
    <p class="mt-0.5 text-xs leading-relaxed text-brand-moss">
        {{ ($editing_deploy_hook_id ?? null) !== null
            ? __('When and type for this hook. Change placement from the timeline if needed.')
            : __('Comes from the timeline slot you dropped onto.') }}
    </p>
    <div class="mt-3 flex flex-wrap items-center gap-2">
        <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold ring-1 {{ $kindTone }}">
            <x-dynamic-component :component="$kindIcon" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            {{ $kindLabel }}
        </span>
        <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
        <span class="inline-flex items-center rounded-full bg-white/80 px-3 py-1.5 text-xs font-semibold text-brand-ink ring-1 ring-brand-ink/10">
            {{ $anchorLabel }}
        </span>
        @if ($afterStep)
            <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
            <span class="inline-flex items-center gap-1.5 rounded-full border border-sky-200/80 bg-sky-50 px-3 py-1.5 text-xs font-semibold text-sky-900 ring-1 ring-sky-200/50">
                <x-heroicon-m-queue-list class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                {{ $afterStep->pillLabel() }}
            </span>
        @endif
    </div>
</div>
