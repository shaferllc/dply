@php
    $billable = $this->billableServers;
    $excluded = $this->excludedServers;
    $hasAnyServer = $billable->isNotEmpty() || $excluded->isNotEmpty();
@endphp

<div class="dply-card overflow-hidden">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
            <x-heroicon-o-server-stack class="h-5 w-5" aria-hidden="true" />
        </span>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Fleet') }}</p>
            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Your fleet') }}</h2>
            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                {{ __('Servers dply is tracking. Ready, mature servers count toward your bill — fresh or paused servers don\'t.') }}
            </p>
            <p class="mt-2 text-xs text-brand-moss/80">
                {{ trans_choice('{0} New servers count once they\'re past today.|{1} New servers count once they\'ve been up for :days day.|[2,*] New servers count once they\'ve been up for :days days.', (int) config('subscription.standard.min_billable_age_days', 1), ['days' => (int) config('subscription.standard.min_billable_age_days', 1)]) }}
            </p>
        </div>
    </div>
    <div class="px-6 py-6 sm:px-7">
        <div class="space-y-4">
            @if (! $hasAnyServer)
                <div class="rounded-xl border border-dashed border-brand-ink/15 bg-white/40 px-5 py-8 text-center">
                    <p class="text-sm text-brand-moss">{{ __('No servers yet.') }}</p>
                    <a href="{{ route('servers.create') }}" wire:navigate class="mt-2 inline-flex items-center text-sm font-medium text-brand-sage hover:text-brand-ink">{{ __('Connect your first server →') }}</a>
                </div>
            @else
                <div class="overflow-hidden rounded-xl border border-brand-ink/10">
                    <table class="w-full text-sm">
                        <thead class="bg-brand-cream/60 text-brand-ink/70">
                            <tr>
                                <th class="px-4 py-2.5 text-left font-semibold">{{ __('Server') }}</th>
                                <th class="px-4 py-2.5 text-left font-semibold">{{ __('Size') }}</th>
                                <th class="px-4 py-2.5 text-right font-semibold">{{ __('Plan fee') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-ink/5">
                            @foreach ($billable as $server)
                                @php
                                    $tier = $server->billingTier();
                                @endphp
                                <tr class="bg-white/40">
                                    <td class="px-4 py-3">
                                        <a href="{{ route('servers.show', $server) }}" wire:navigate class="font-medium text-brand-ink hover:text-brand-sage">{{ $server->name }}</a>
                                        <p class="text-xs text-brand-moss/80 mt-0.5">{{ $server->provider?->label() ?? __('Custom') }}</p>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex items-center rounded-md bg-brand-sand/40 px-2 py-0.5 text-xs font-semibold uppercase text-brand-ink">{{ $tier->label() }}</span>
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
                                    <td class="px-4 py-3" colspan="2">
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
</div>
