{{--
  Full preflight + cost preview panel, lifted from the original Servers/Create page.
  Required: $preflight (array)
--}}
@php
    $preflightBadgeClasses = match ($preflight['status'] ?? 'blocked') {
        'ready' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'warning' => 'bg-amber-50 text-amber-800 ring-amber-200',
        default => 'bg-rose-50 text-rose-700 ring-rose-200',
    };
    // Closure used by livewire.servers.partials.preflight-check-row — was defined at the
    // top of the original create.blade.php; the row partial relies on it being in scope.
    $preflightItemClasses = static function (string $severity): string {
        return match ($severity) {
            'info' => 'border-emerald-200 bg-emerald-50/70 text-emerald-900',
            'warning' => 'border-amber-200 bg-amber-50 text-amber-900',
            default => 'border-rose-200 bg-rose-50 text-rose-900',
        };
    };
@endphp

<div class="space-y-4 rounded-2xl border border-slate-200 bg-slate-50/70 p-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h3 class="text-base font-semibold text-slate-900">{{ __('Preflight and cost preview') }}</h3>
            <p class="mt-1 text-sm text-slate-600">{{ $preflight['summary'] ?? '' }}</p>
        </div>
        <span class="inline-flex items-center rounded-full px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] ring-1 {{ $preflightBadgeClasses }}">
            {{ match ($preflight['status'] ?? 'blocked') {
                'ready' => __('Ready'),
                'warning' => __('Needs review'),
                default => __('Blocked'),
            } }}
        </span>
    </div>

    {{-- Single-column list of preflight check groups. The cost-preview
         section that used to share this grid lives in its own partial
         (_cost-preview-panel.blade.php) so it can be placed in the
         review-page sidebar without squeezing into a half-width cell. --}}
    <div class="space-y-4">
        @foreach (($preflight['groups'] ?? []) as $groupKey => $groupChecks)
            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                    {{ match ($groupKey) {
                        'account_readiness' => __('Account readiness'),
                        'infrastructure_selection' => __('Infrastructure selection'),
                        'stack_readiness' => __('Stack readiness'),
                        'verification' => __('Verification'),
                        default => __('Cost clarity'),
                    } }}
                </p>
                <div class="mt-3 space-y-3">
                    @foreach ($groupChecks as $check)
                        @include('livewire.servers.partials.preflight-check-row', ['check' => $check])
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
</div>
