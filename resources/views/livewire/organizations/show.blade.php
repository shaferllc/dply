<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-organization-shell
            dense
            :organization="$organization"
            section="overview"
            :title="$organization->name"
            :description="__('Plan, people, and everything dply automates on your behalf.')"
            icon="heroicon-o-building-office-2"
            :breadcrumb="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => $organization->name, 'icon' => 'building-office-2'],
            ]"
        >
            {{-- The overview as a ledger: one row per fact, each stating a
                 number, the sentence that qualifies it, and the page that
                 changes it. Replaced a Sections tile grid that repeated the
                 sidebar sitting immediately to its left. --}}
            <ul class="divide-y divide-brand-ink/10">
                @foreach ($ledger as $row)
                    <li wire:key="ledger-{{ $loop->index }}" class="grid grid-cols-1 gap-x-5 gap-y-1 px-3 py-3 sm:grid-cols-[7rem_minmax(0,1fr)_auto] sm:items-baseline sm:px-4">
                        <span class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ $row['label'] }}</span>

                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-brand-ink">
                                {{ $row['value'] }}
                                @if ($row['flag'])
                                    <span class="text-amber-700">· {{ $row['flag'] }}</span>
                                @endif
                            </p>
                            <p class="mt-0.5 text-xs leading-relaxed text-brand-moss">{{ $row['detail'] }}</p>
                        </div>

                        <a
                            href="{{ $row['href'] }}"
                            wire:navigate
                            class="inline-flex shrink-0 items-center gap-1 self-start whitespace-nowrap text-xs font-semibold text-brand-sage hover:text-brand-ink sm:self-baseline"
                        >
                            {{ $row['cta'] }}
                            <x-heroicon-m-arrow-up-right class="h-3 w-3 shrink-0" aria-hidden="true" />
                        </a>
                    </li>
                @endforeach
            </ul>
        </x-organization-shell>
    </div>
</div>
