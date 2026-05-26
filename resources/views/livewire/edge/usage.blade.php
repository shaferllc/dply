<div class="dply-page-shell space-y-6 pt-6">
    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Infrastructure'), 'href' => route('infrastructure.index'), 'icon' => 'rectangle-group'],
        ['label' => __('Edge'), 'href' => route('edge.index'), 'icon' => 'globe-alt'],
        ['label' => __('Usage'), 'icon' => 'chart-bar'],
    ]" />

    <x-page-header
        :title="__('Edge usage')"
        :description="__('Cross-site view of requests, bandwidth, storage, and estimated cost for the current calendar month.')"
        compact
        flush
    >
        <x-slot name="leading">
            <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl border border-brand-ink/10 bg-white shadow-sm">
                <x-heroicon-o-chart-bar class="h-7 w-7 text-brand-ink" aria-hidden="true" />
            </span>
        </x-slot>
        <x-slot name="actions">
            <a href="{{ route('edge.index') }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink transition hover:bg-brand-sand/40">
                <x-heroicon-o-arrow-left class="h-3.5 w-3.5" />
                {{ __('Back to Edge sites') }}
            </a>
        </x-slot>
    </x-page-header>

    @unless ($edgeEnabled)
        <div class="rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-900">
            {{ __('Edge is not enabled for this organization.') }}
        </div>
    @else
        @php
            $humanBytes = static function (int $b): string {
                if ($b <= 0) {
                    return '0 B';
                }
                $units = ['B', 'KB', 'MB', 'GB', 'TB'];
                $i = (int) min(count($units) - 1, floor(log($b, 1024)));

                return sprintf('%.1f %s', $b / (1024 ** $i), $units[$i]);
            };
            $window = $totals['window'] ?? null;
        @endphp

        {{-- Org-wide totals strip --}}
        @if ($totals)
            <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                <div class="rounded-xl border border-brand-ink/10 bg-white px-5 py-4">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-brand-mist">{{ __('Edge sites') }}</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-brand-ink">{{ number_format($totals['all_sites']) }}</p>
                    <p class="mt-0.5 text-[10px] text-brand-mist">{{ __(':n billable', ['n' => number_format($totals['sites'])]) }}</p>
                </div>
                <div class="rounded-xl border border-brand-ink/10 bg-white px-5 py-4">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-brand-mist">{{ __('Requests (MTD)') }}</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-brand-ink">{{ number_format($totals['requests']) }}</p>
                </div>
                <div class="rounded-xl border border-brand-ink/10 bg-white px-5 py-4">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-brand-mist">{{ __('Egress (MTD)') }}</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-brand-ink">{{ $humanBytes($totals['bytes_egress']) }}</p>
                </div>
                <div class="rounded-xl border border-brand-ink/10 bg-white px-5 py-4">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-brand-mist">{{ __('R2 storage') }}</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-brand-ink">{{ $humanBytes($totals['r2_storage_bytes']) }}</p>
                </div>
                <div class="rounded-xl border border-brand-ink/10 bg-white px-5 py-4">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-brand-mist">{{ __('Est. total / mo') }}</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-brand-ink">${{ number_format($totals['total_cents'] / 100, 2) }}</p>
                    <p class="mt-0.5 text-[10px] text-brand-mist">
                        {{ __(':platform platform + :usage usage', [
                            'platform' => '$'.number_format($totals['platform_cents'] / 100, 2),
                            'usage' => '$'.number_format($totals['usage_cents'] / 100, 2),
                        ]) }}
                    </p>
                </div>
            </section>

            <p class="text-xs text-brand-moss">
                {{ __('Window: :start → :end', ['start' => $window['start'] ?? '', 'end' => $window['end'] ?? '']) }}
            </p>
        @endif

        @if (empty($rows))
            <div class="dply-card flex flex-col items-center justify-center gap-2 px-6 py-12 text-center">
                <x-heroicon-o-chart-bar class="h-8 w-8 text-brand-moss/60" />
                <p class="text-sm text-brand-moss">{{ __('No billable Edge sites with usage in this window yet.') }}</p>
                <a href="{{ route('edge.index') }}" wire:navigate class="text-xs font-semibold text-brand-forest hover:underline dark:text-brand-sage">
                    {{ __('Go to Edge sites →') }}
                </a>
            </div>
        @else
            <div class="dply-card overflow-hidden">
                <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                    <thead class="bg-brand-sand/30 text-left text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-moss">
                        <tr>
                            <th class="px-4 py-3">{{ __('Site') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Requests') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Egress') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('R2 storage') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Platform') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Usage') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Est. total') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-ink/8 text-brand-ink">
                        @foreach ($rows as $row)
                            @php
                                $siteName = $row['name'] ?? $row['site_id'] ?? '—';
                                $hostname = $row['hostname'] ?? null;
                                $serverId = $row['server_id'] ?? null;
                                $siteId = $row['site_id'] ?? null;
                                $billable = (bool) ($row['billable'] ?? false);
                                $status = (string) ($row['status'] ?? '');
                                $statusTone = match ($status) {
                                    'edge_active' => 'bg-emerald-100 text-emerald-800',
                                    'edge_failed' => 'bg-rose-100 text-rose-800',
                                    'edge_provisioning' => 'bg-sky-100 text-sky-800',
                                    default => 'bg-brand-sand/60 text-brand-moss',
                                };
                            @endphp
                            <tr @class(['opacity-60' => ! $billable])>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="font-semibold text-brand-ink truncate">{{ $siteName }}</p>
                                        @if (! empty($row['is_preview']))
                                            <span class="rounded-full bg-brand-gold/15 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-olive">{{ __('Preview') }}</span>
                                        @elseif (! $billable && $status !== '')
                                            <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $statusTone }}">{{ str_replace('_', ' ', $status) }}</span>
                                        @endif
                                    </div>
                                    @if ($hostname)
                                        <p class="mt-0.5 truncate font-mono text-[11px] text-brand-moss">{{ $hostname }}</p>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ number_format($row['requests'] ?? 0) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ $humanBytes((int) ($row['bytes_egress'] ?? 0)) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ $humanBytes((int) ($row['r2_storage_bytes'] ?? 0)) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">${{ number_format(($row['platform_cents'] ?? 0) / 100, 2) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">${{ number_format(($row['usage_cents'] ?? 0) / 100, 2) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums font-semibold">${{ number_format(($row['total_cents'] ?? 0) / 100, 2) }}</td>
                                <td class="px-4 py-3 text-right">
                                    @if ($serverId && $siteId)
                                        <a
                                            href="{{ route('sites.show', ['server' => $serverId, 'site' => $siteId, 'section' => 'edge-billing']) }}"
                                            wire:navigate
                                            class="inline-flex items-center gap-1 text-xs font-semibold text-brand-forest hover:underline dark:text-brand-sage"
                                        >
                                            {{ __('Site billing →') }}
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endunless
</div>
