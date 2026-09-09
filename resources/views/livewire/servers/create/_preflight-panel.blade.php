{{--
  Full preflight + cost preview panel.
  Required: $preflight (array)

  Dense by design: this is a checklist you scan, not prose you read. Each check
  used to be a fat tinted card (px-4 py-3, text-sm, wrapped detail) inside a
  nested white card with its own uppercase heading — eight checks filled a
  screen and a half, and the one WARNING that actually needed attention looked
  exactly like the seven READY rows. Now the groups are hairline bands, ready
  rows collapse to a single truncated line, and anything not-ready keeps its
  detail on a second line so it still reads as the exception.
--}}
@php
    $preflightStatus = $preflight['status'] ?? 'blocked';
    $preflightBadgeClasses = match ($preflightStatus) {
        'ready' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'warning' => 'bg-amber-50 text-amber-800 ring-amber-200',
        default => 'bg-rose-50 text-rose-700 ring-rose-200',
    };
    // Closure used by livewire.servers.partials.preflight-check-row — the row
    // partial relies on it being in scope.
    $preflightItemClasses = static function (string $severity): string {
        return match ($severity) {
            'info' => 'border-emerald-300 bg-emerald-50/60 text-emerald-900',
            'warning' => 'border-amber-300 bg-amber-50 text-amber-900',
            default => 'border-rose-300 bg-rose-50 text-rose-900',
        };
    };
@endphp

<section class="dply-card overflow-hidden">
    <x-workspace-panel-head
        dense
        icon="heroicon-o-clipboard-document-check"
        :title="__('Preflight and cost preview')"
        :note="$preflight['summary'] ?? ''"
        :tone="match ($preflightStatus) { 'ready' => null, 'warning' => 'amber', default => 'danger' }"
        class="border-b border-brand-ink/10"
    >
        <x-slot:actions>
            <span class="inline-flex h-6 shrink-0 items-center rounded-full px-2 text-2xs font-semibold uppercase tracking-[0.14em] ring-1 {{ $preflightBadgeClasses }}">
                {{ match ($preflightStatus) {
                    'ready' => __('Ready'),
                    'warning' => __('Needs review'),
                    default => __('Blocked'),
                } }}
            </span>
        </x-slot:actions>
    </x-workspace-panel-head>

    {{-- Single-column list of preflight check groups. The cost-preview section
         that used to share this grid lives in its own partial
         (_cost-preview-panel.blade.php) so it can be placed in the review-page
         sidebar without squeezing into a half-width cell. --}}
    @foreach (($preflight['groups'] ?? []) as $groupKey => $groupChecks)
        @php
            $groupNotReady = collect($groupChecks)->filter(fn (array $c): bool => ($c['severity'] ?? 'info') !== 'info')->count();
        @endphp
        <div class="flex items-center gap-2 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-1.5 sm:px-4">
            <p class="shrink-0 text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">
                {{ match ($groupKey) {
                    'account_readiness' => __('Account readiness'),
                    'infrastructure_selection' => __('Infrastructure selection'),
                    'stack_readiness' => __('Stack readiness'),
                    'verification' => __('Verification'),
                    default => __('Cost clarity'),
                } }}
            </p>
            <span class="h-px min-w-0 flex-1 bg-brand-ink/8" aria-hidden="true"></span>
            {{-- Count only what needs attention; "3 checks" on an all-green
                 group is a number nobody acts on. --}}
            @if ($groupNotReady > 0)
                <span class="shrink-0 text-2xs font-semibold tabular-nums text-amber-800">{{ $groupNotReady }}</span>
            @else
                <x-heroicon-s-check-circle class="h-3.5 w-3.5 shrink-0 text-emerald-500" aria-hidden="true" />
            @endif
        </div>
        <div class="divide-y divide-brand-ink/8 border-b border-brand-ink/10 last:border-b-0">
            @foreach ($groupChecks as $check)
                @include('livewire.servers.partials.preflight-check-row', ['check' => $check])
            @endforeach
        </div>
    @endforeach
</section>
