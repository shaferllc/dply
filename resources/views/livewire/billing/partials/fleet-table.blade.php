@php
    $billable = $this->billableServers;
    $excluded = $this->excludedServers;
    $hasAnyServer = $billable->isNotEmpty() || $excluded->isNotEmpty();
@endphp

@php
    $minAgeDays = (int) config('subscription.standard.min_billable_age_days', 1);
    // Built here rather than inline in the :note attribute — Blade's component
    // attribute parser chokes on escaped apostrophes, so long prose with
    // contractions belongs in a php block, not in the tag.
    $fleetNote = __('Ready, mature servers count toward your bill — fresh or paused servers don\'t. ')
        .trans_choice('{0} New servers count once they\'re past today.|{1} New servers count once they\'ve been up for :days day.|[2,*] New servers count once they\'ve been up for :days days.', $minAgeDays, ['days' => $minAgeDays]);
@endphp

<section class="border-b border-brand-ink/10">
    {{-- The min-billable-age note moved into the panel-head note: it is the one
         thing that explains why a server on the list isn't being charged for. --}}
    <x-workspace-panel-head
        dense
        icon="heroicon-o-server-stack"
        :title="__('Your fleet')"
        :note="$fleetNote"
    />
    <div class="px-3 py-2.5 sm:px-4">
        <div class="space-y-2.5">
            @if (! $hasAnyServer)
                <div class="rounded-xl border border-dashed border-brand-ink/15 bg-white/40 px-3 py-6 text-center">
                    <p class="text-sm text-brand-moss">{{ __('No servers yet.') }}</p>
                    <a href="{{ route('servers.create') }}" wire:navigate class="mt-2 inline-flex items-center text-sm font-medium text-brand-sage hover:text-brand-ink">{{ __('Connect your first server →') }}</a>
                </div>
            @else
                <div class="overflow-hidden rounded-xl border border-brand-ink/10">
                    <table class="w-full text-sm">
                        <thead class="bg-brand-cream/60 text-brand-ink/70">
                            <tr>
                                <th class="px-4 py-2.5 text-left font-semibold">{{ __('Server') }}</th>
                                <th class="px-4 py-2.5 text-right font-semibold">{{ __('Plan fee') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-ink/5">
                            @foreach ($billable as $server)
                                <tr class="bg-white/40">
                                    <td class="px-4 py-3">
                                        <a href="{{ route('servers.show', $server) }}" wire:navigate class="font-medium text-brand-ink hover:text-brand-sage">{{ $server->name }}</a>
                                        <p class="text-xs text-brand-moss/80 mt-0.5">{{ $server->provider?->label() ?? __('Custom') }}</p>
                                    </td>
                                    <td class="px-4 py-3 text-right text-xs text-brand-moss">{{ __('Included in plan') }}</td>
                                </tr>
                            @endforeach
                            @foreach ($excluded as $row)
                                <tr class="bg-brand-cream/30 text-brand-moss/80">
                                    <td class="px-4 py-3">
                                        <a href="{{ route('servers.show', $row['server']) }}" wire:navigate class="font-medium hover:text-brand-ink">{{ $row['server']->name }}</a>
                                        <p class="text-xs mt-0.5">{{ $row['server']->provider?->label() ?? __('Custom') }}</p>
                                    </td>
                                    {{-- Two columns now the Size column is gone; no colspan needed. --}}
                                    <td class="px-4 py-3">
                                        <span class="inline-flex items-center gap-1 text-xs">
                                            <x-heroicon-o-information-circle class="h-4 w-4 shrink-0 opacity-70" aria-hidden="true" />
                                            {{ __('Not billed') }} — {{ $row['reason'] }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</section>
