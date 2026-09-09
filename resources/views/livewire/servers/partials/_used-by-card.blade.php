@php
    /**
     * "Used by" card — sites that consume this server resource via a SiteBinding.
     *
     * @var array<int, array<string, mixed>> $rows   consumer rows (see SurfacesBindingConsumers)
     * @var string $resourceNoun                      'database' | 'cache' (copy only)
     * @var bool   $showResource                      show the per-row resource column (DB tab lists several)
     */
    $rows = $rows ?? [];
    $resourceNoun = $resourceNoun ?? 'resource';
    $showResource = $showResource ?? false;
@endphp
<div class="{{ $card ?? 'dply-card overflow-hidden' }} overflow-hidden">
    <x-workspace-panel-head
        dense
        icon="heroicon-o-link"
        :title="__('Attached sites')"
        :count="count($rows) > 0 ? trans_choice('{1} :count site|[2,*] :count sites', count($rows), ['count' => count($rows)]) : null"
        :note="__('Sites that have this :noun attached as a resource. Sites on another server reach it over the private network — those are flagged remote.', ['noun' => $resourceNoun])"
        class="border-b border-brand-ink/10"
    />
    <div class="px-4 py-3.5 sm:px-5">
        @if (count($rows) === 0)
            <x-empty-state
                compact
                borderless
                icon="heroicon-o-link"
                tone="sage"
                :title="__('No sites attached yet')"
                :description="__('When a site attaches this :noun as a resource, it appears here with its connection status.', ['noun' => $resourceNoun])"
            />
        @else
            <div class="overflow-x-auto rounded-xl border border-brand-ink/10">
                <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                    <thead class="bg-brand-cream/40">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-brand-mist">
                            <th class="px-3 py-2">{{ __('Site') }}</th>
                            <th class="px-3 py-2">{{ __('Server') }}</th>
                            @if ($showResource)
                                <th class="px-3 py-2">{{ __(ucfirst($resourceNoun)) }}</th>
                            @endif
                            <th class="px-3 py-2">{{ __('Type') }}</th>
                            <th class="px-3 py-2">{{ __('Reachability') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-ink/5">
                        @foreach ($rows as $row)
                            <tr class="align-top">
                                <td class="px-3 py-2">
                                    <a href="{{ $row['site_url'] }}"
                                       class="font-medium text-brand-ink hover:text-brand-forest hover:underline">{{ $row['site_name'] }}</a>
                                </td>
                                <td class="px-3 py-2">
                                    <span class="text-brand-moss">{{ $row['server_name'] }}</span>
                                    @if ($row['is_remote'])
                                        <span class="ml-1.5 inline-flex items-center rounded-full bg-brand-sage/15 px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide text-brand-forest ring-1 ring-brand-sage/25">{{ __('Remote') }}</span>
                                    @else
                                        <span class="ml-1.5 inline-flex items-center rounded-full bg-brand-ink/5 px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Local') }}</span>
                                    @endif
                                </td>
                                @if ($showResource)
                                    <td class="px-3 py-2 text-brand-moss">{{ $row['resource_name'] ?? '—' }}</td>
                                @endif
                                <td class="px-3 py-2 text-brand-moss">{{ ucfirst($row['type']) }}</td>
                                <td class="px-3 py-2">
                                    @if ($row['reachable'] === true)
                                        <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-600/20">
                                            <x-heroicon-s-check-circle class="h-4 w-4" aria-hidden="true" /> {{ __('Reachable') }}
                                        </span>
                                    @elseif ($row['reachable'] === false)
                                        <div class="space-y-1.5">
                                            <span class="inline-flex items-center gap-1 rounded-full bg-red-50 px-2 py-0.5 text-xs font-semibold text-red-700 ring-1 ring-red-600/20">
                                                <x-heroicon-s-x-circle class="h-4 w-4" aria-hidden="true" /> {{ __('Unreachable') }}
                                            </span>
                                            @if (! empty($row['detail']))
                                                <p class="max-w-md text-xs leading-relaxed text-brand-mist">{{ $row['detail'] }}</p>
                                            @endif
                                            <a href="{{ $row['fix_url'] }}" class="inline-flex items-center gap-1 text-xs font-semibold text-brand-forest hover:underline">
                                                {{ __('Fix connectivity on the site') }}
                                                <x-heroicon-o-arrow-top-right-on-square class="h-3 w-3" aria-hidden="true" />
                                            </a>
                                        </div>
                                    @else
                                        <span class="inline-flex items-center gap-1 rounded-full bg-brand-ink/5 px-2 py-0.5 text-xs font-semibold text-brand-mist">{{ __('Not checked') }}</span>
                                    @endif
                                    @if (! empty($row['checked_at']))
                                        <p class="mt-1 text-xs text-brand-mist">{{ __('Checked :ago', ['ago' => \Illuminate\Support\Carbon::parse($row['checked_at'])->diffForHumans()]) }}</p>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
