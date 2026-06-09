<section class="dply-card overflow-hidden">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <x-icon-badge>
            <x-heroicon-o-server-stack class="h-5 w-5" aria-hidden="true" />
        </x-icon-badge>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Inventory') }}</p>
            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Packages & OS detection') }}</h2>
            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                {{ __('Inventory probe snapshot — read-only package list, not an install plan.') }}
            </p>
        </div>
        <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-white px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-brand-moss ring-1 ring-brand-ink/10">
            <x-heroicon-o-eye class="h-4 w-4" aria-hidden="true" />
            {{ __('Read-only') }}
        </span>
    </div>

    <div class="space-y-6 px-6 py-5 sm:px-7">
        <div class="flex flex-col gap-4 rounded-2xl border border-brand-ink/10 bg-brand-sand/10 p-4 sm:flex-row sm:items-start sm:p-5">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-white text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-server-stack class="h-5 w-5" aria-hidden="true" />
            </span>
            <dl class="grid min-w-0 flex-1 gap-4 sm:grid-cols-2">
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-[0.12em] text-brand-mist">{{ __('OS label (in Dply)') }}</dt>
                    <dd class="mt-1 text-sm font-semibold text-brand-ink">
                        {{ $osVersions[$report['os']['label'] ?? ''] ?? ($report['os']['label'] ?? '—') }}
                    </dd>
                </div>
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-[0.12em] text-brand-mist">{{ __('Detected on server') }}</dt>
                    <dd class="mt-1 text-sm text-brand-ink">
                        @if ($report['os']['pretty'])
                            <span class="font-semibold">{{ $report['os']['pretty'] }}</span>
                            @if ($report['os']['key'])
                                <span class="block text-xs text-brand-moss">{{ $osVersions[$report['os']['key']] ?? $report['os']['key'] }}</span>
                            @endif
                        @else
                            <span class="text-brand-moss">—</span>
                        @endif
                    </dd>
                    @if ($opsReady && ! $isDeployer && $report['os']['key'] && ($report['os']['label'] ?? '') !== ($report['os']['key'] ?? ''))
                        <button
                            type="button"
                            wire:click="applyDetectedOsFromInventory"
                            class="mt-2 inline-flex items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40"
                        >{{ __('Use detected label') }}</button>
                    @endif
                </div>
            </dl>
        </div>

        @if (count($report['packages']['rows']) > 0)
            <div x-data="{ filter: 'all', q: '' }">
                <div class="flex flex-col gap-4 border-b border-brand-ink/10 pb-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h3 class="text-sm font-semibold text-brand-ink">{{ __('Outdated packages') }}</h3>
                        <p class="mt-1 text-xs text-brand-moss">
                            {{ trans_choice(':count listed from apt.|:count listed from apt.', count($report['packages']['rows']), ['count' => count($report['packages']['rows'])]) }}
                            @if ($report['packages']['security'] > 0)
                                <span class="font-medium text-red-700">{{ trans_choice(':n security update.|:n security updates.', $report['packages']['security'], ['n' => $report['packages']['security']]) }}</span>
                            @endif
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <div class="inline-flex rounded-xl border border-brand-ink/15 bg-white p-1 text-xs shadow-sm">
                            <button type="button" x-on:click="filter = 'all'" :class="filter === 'all' ? 'bg-brand-sage/15 text-brand-ink shadow-sm' : 'text-brand-moss hover:text-brand-ink'" class="rounded-lg px-3 py-1.5 font-semibold transition">{{ __('All') }}</button>
                            <button type="button" x-on:click="filter = 'security'" :class="filter === 'security' ? 'bg-red-100 text-red-800 shadow-sm' : 'text-brand-moss hover:text-brand-ink'" class="rounded-lg px-3 py-1.5 font-semibold transition">{{ __('Security') }}</button>
                        </div>
                        <label class="relative block">
                            <span class="sr-only">{{ __('Filter by name') }}</span>
                            <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-brand-mist" aria-hidden="true" />
                            <input
                                type="search"
                                x-model="q"
                                placeholder="{{ __('Filter packages…') }}"
                                class="w-full min-w-[11rem] rounded-xl border border-brand-ink/15 bg-white py-2 pl-9 pr-3 text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30 sm:w-52"
                            />
                        </label>
                    </div>
                </div>

                <div class="mt-4 overflow-hidden rounded-2xl border border-brand-ink/10 bg-white shadow-sm">
                    <div class="max-h-[32rem] overflow-auto">
                        <table class="min-w-full text-left text-xs">
                            <thead class="sticky top-0 z-10 bg-brand-cream/95 text-[11px] uppercase tracking-[0.12em] text-brand-mist backdrop-blur-sm">
                                <tr class="border-b border-brand-ink/10">
                                    <th class="px-4 py-3 font-semibold">{{ __('Package') }}</th>
                                    <th class="px-4 py-3 font-semibold">{{ __('Current') }}</th>
                                    <th class="px-4 py-3 font-semibold">{{ __('New') }}</th>
                                    <th class="px-4 py-3 font-semibold">{{ __('Source') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-brand-ink/5">
                                @foreach ($report['packages']['rows'] as $row)
                                    <tr
                                        x-show="(filter === 'all' || {{ $row['is_security'] ? 'true' : 'false' }}) && (q === '' || @js($row['name']).toLowerCase().includes(q.toLowerCase()))"
                                        @class([
                                            'bg-red-50/30' => $row['is_security'],
                                            'hover:bg-brand-sand/20' => ! $row['is_security'],
                                            'hover:bg-red-50/50' => $row['is_security'],
                                        ])
                                    >
                                        <td class="px-4 py-3 align-top">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span class="font-mono text-sm font-medium text-brand-ink">{{ $row['name'] }}</span>
                                                @if ($row['is_security'])
                                                    <span class="inline-flex items-center rounded-full bg-red-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-red-800 ring-1 ring-red-200">{{ __('Security') }}</span>
                                                @endif
                                            </div>
                                        </td>
                                        <td class="max-w-[14rem] px-4 py-3 align-top">
                                            <span class="block truncate font-mono text-[11px] leading-relaxed text-brand-moss" title="{{ $row['current_version'] ?? '—' }}">{{ $row['current_version'] ?? '—' }}</span>
                                        </td>
                                        <td class="max-w-[14rem] px-4 py-3 align-top">
                                            <span class="block truncate font-mono text-[11px] font-medium leading-relaxed text-brand-ink" title="{{ $row['new_version'] ?? '—' }}">{{ $row['new_version'] ?? '—' }}</span>
                                        </td>
                                        <td class="px-4 py-3 align-top">
                                            <div class="flex flex-wrap gap-1">
                                                @foreach (explode(',', (string) ($row['sources'] ?? '')) as $sourcePart)
                                                    @php $sourcePart = trim($sourcePart); @endphp
                                                    @if ($sourcePart !== '')
                                                        <span @class([
                                                            'inline-flex rounded-md px-1.5 py-0.5 font-mono text-[10px] ring-1',
                                                            'bg-red-50 text-red-800 ring-red-200' => str_contains($sourcePart, 'security'),
                                                            'bg-brand-sand/40 text-brand-moss ring-brand-ink/10' => ! str_contains($sourcePart, 'security'),
                                                        ])>{{ $sourcePart }}</span>
                                                    @endif
                                                @endforeach
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                @if ($report['packages']['preview_truncated'])
                    <p class="mt-3 flex items-start gap-2 rounded-xl border border-amber-200/80 bg-amber-50/50 px-3 py-2 text-xs text-amber-950">
                        <x-heroicon-o-exclamation-triangle class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Package preview was truncated on the server — run Refresh scan for the full list.') }}
                    </p>
                @endif
            </div>
        @elseif (! $report['supports_apt'] && ! $report['inventory']['never_scanned'])
            <div class="rounded-2xl border border-dashed border-brand-ink/15 bg-brand-sand/10 px-5 py-8 text-center">
                <p class="text-sm text-brand-moss">{{ __('apt inventory is not available on this OS yet — only Debian/Ubuntu hosts are supported in v1.') }}</p>
            </div>
        @elseif ($report['inventory']['never_scanned'])
            <div class="rounded-2xl border border-dashed border-brand-ink/15 bg-brand-sand/10 px-5 py-8 text-center">
                <p class="text-sm text-brand-moss">{{ __('No inventory scan on record yet — run Refresh scan to populate package data.') }}</p>
                @if ($opsReady && ! $isDeployer)
                    <button
                        type="button"
                        wire:click="refreshServerInventoryDetails"
                        wire:loading.attr="disabled"
                        wire:target="refreshServerInventoryDetails"
                        class="mt-4 inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:opacity-50"
                    >
                        <span wire:loading.remove wire:target="refreshServerInventoryDetails" class="inline-flex items-center gap-1.5">
                            <x-heroicon-o-arrow-path class="h-4 w-4" aria-hidden="true" />
                            {{ __('Refresh scan') }}
                        </span>
                        <span wire:loading wire:target="refreshServerInventoryDetails" class="inline-flex items-center gap-1.5">
                            <x-heroicon-o-arrow-path class="h-4 w-4 animate-spin" aria-hidden="true" />
                            {{ __('Scanning…') }}
                        </span>
                    </button>
                @endif
            </div>
        @else
            <div class="rounded-2xl border border-dashed border-brand-ink/15 bg-brand-sand/10 px-5 py-8 text-center">
                <p class="text-sm text-brand-moss">{{ __('No outdated packages reported on the last scan.') }}</p>
            </div>
        @endif
    </div>
</section>
