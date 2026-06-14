@php
    // Shared section-header chrome (matches billing.show + analytics + automation).
    $tonePalette = [
        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        'sky' => 'bg-sky-50 text-sky-700 ring-sky-200',
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'sand' => 'bg-brand-sand/55 text-brand-forest ring-brand-ink/10',
    ];
@endphp

<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-organization-shell :organization="$organization" section="invoices" :breadcrumb="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => $organization->name, 'href' => route('organizations.show', $organization), 'icon' => 'building-office-2'],
            ['label' => __('Invoices'), 'icon' => 'document-text'],
        ]">
            <x-livewire-validation-errors />

            {{-- Hero: positioning + at-a-glance counts. Cross-links into the
                 sibling billing screens so an admin can pivot quickly. --}}
            @php
                $total = $rows->total();
                $currentPage = $rows->currentPage();
                $lastPage = max(1, $rows->lastPage());
                $perPage = (int) ($perPage ?? $rows->perPage());
            @endphp
            <x-hero-card
                :eyebrow="__('Billing')"
                :title="__('Invoices')"
                :description="__('Stripe invoices for :org. Search, sort, and open the PDF for any line item.', ['org' => $organization->name])"
                icon="document-text"
            >
                <x-outline-link href="{{ route('billing.show', $organization) }}" wire:navigate>
                    <x-heroicon-o-credit-card class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                    {{ __('Billing & plan') }}
                </x-outline-link>
                <x-outline-link href="{{ route('billing.analytics', $organization) }}" wire:navigate>
                    <x-heroicon-o-chart-bar class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                    {{ __('Analytics') }}
                </x-outline-link>

                <x-slot:stats>
                    <dl class="grid grid-cols-3 gap-2">
                        <div @class([
                            'rounded-2xl border px-4 py-3 shadow-sm',
                            'border-brand-sage/30 bg-brand-sage/8' => $hasStripeCustomer,
                            'border-brand-ink/10 bg-white' => ! $hasStripeCustomer,
                        ])>
                            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Stripe') }}</dt>
                            <dd class="mt-1 flex items-center gap-1.5">
                                @if ($hasStripeCustomer)
                                    <span class="inline-block h-2 w-2 rounded-full bg-brand-sage" aria-hidden="true"></span>
                                    <span class="text-sm font-semibold text-brand-ink">{{ __('Linked') }}</span>
                                @else
                                    <span class="inline-block h-2 w-2 rounded-full bg-brand-ink/15" aria-hidden="true"></span>
                                    <span class="text-sm font-semibold text-brand-ink">{{ __('No customer') }}</span>
                                @endif
                            </dd>
                            <p class="mt-1 text-[11px] text-brand-mist">{{ __('Customer record') }}</p>
                        </div>
                        <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Invoices') }}</dt>
                            <dd class="mt-1 flex items-baseline gap-1.5">
                                <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $total }}</span>
                                <span class="text-[11px] text-brand-moss">{{ trans_choice('total|total', $total) }}</span>
                            </dd>
                            @if ($total > 0 && $search !== '')
                                <p class="mt-1 truncate text-[11px] text-brand-mist" title="{{ __('Filtered by :q', ['q' => $search]) }}">{{ __('Filtered') }}</p>
                            @endif
                        </div>
                        <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Page') }}</dt>
                            <dd class="mt-1 flex items-baseline gap-1">
                                <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $currentPage }}</span>
                                <span class="text-[11px] text-brand-moss">/ {{ $lastPage }}</span>
                            </dd>
                            <p class="mt-1 text-[11px] text-brand-mist">{{ trans_choice(':n per page|:n per page', $perPage, ['n' => $perPage]) }}</p>
                        </div>
                    </dl>
                </x-slot:stats>
            </x-hero-card>

            @unless ($hasStripeCustomer)
                <div class="mt-6 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    <span class="inline-flex items-center gap-2">
                        <x-heroicon-o-exclamation-triangle class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('No Stripe customer is linked to this organization yet.') }}
                    </span>
                    <a href="{{ route('billing.show', $organization) }}" wire:navigate class="ms-2 font-semibold underline underline-offset-2 hover:no-underline">
                        {{ __('Subscribe to a plan') }} →
                    </a>
                </div>
            @endunless

            @if ($hasStripeCustomer)
                <section class="dply-card mt-6 overflow-hidden" x-data="{ showColumns: false }">
                    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                        <x-icon-badge>
                            <x-heroicon-o-document class="h-5 w-5" aria-hidden="true" />
                        </x-icon-badge>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('History') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('All invoices') }}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Search by number or description, sort any column, and toggle visibility on a per-column basis.') }}</p>
                        </div>
                    </div>

                    {{-- Toolbar: search + column toggle. Same sandy strip
                         tone the activity / member-directory pages use, so
                         the page reads as part of the same family. --}}
                    <div class="flex flex-col gap-3 border-b border-brand-ink/10 bg-brand-sand/25 px-6 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-7">
                        <div class="relative">
                            <button
                                type="button"
                                @click="showColumns = !showColumns"
                                @click.outside="showColumns = false"
                                class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                            >
                                <x-heroicon-o-view-columns class="h-3.5 w-3.5 shrink-0 text-brand-moss" aria-hidden="true" />
                                {{ __('Columns') }}
                                <x-heroicon-m-chevron-down class="h-3 w-3 shrink-0 opacity-70" aria-hidden="true" />
                            </button>
                            <div
                                x-show="showColumns"
                                x-cloak
                                x-transition
                                class="absolute left-0 z-20 mt-1 w-56 rounded-xl border border-brand-ink/10 bg-white py-2 shadow-lg"
                            >
                                <p class="px-3 pb-2 text-[10px] font-semibold uppercase tracking-wider text-brand-mist">{{ __('Visible columns') }}</p>
                                @foreach (['number' => __('Number'), 'description' => __('Description'), 'status' => __('Status'), 'total' => __('Total'), 'date' => __('Date'), 'actions' => __('Actions')] as $key => $label)
                                    <label class="flex cursor-pointer items-center gap-2 px-3 py-1.5 text-sm text-brand-ink hover:bg-brand-sand/40">
                                        <input type="checkbox" wire:model.live="columns.{{ $key }}" class="h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
                                        {{ $label }}
                                    </label>
                                @endforeach
                            </div>
                        </div>
                        <div class="w-full sm:max-w-sm sm:ml-auto">
                            <label for="invoice-search" class="sr-only">{{ __('Search') }}</label>
                            <div class="relative">
                                <span class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3 text-brand-mist">
                                    <x-heroicon-o-magnifying-glass class="h-4 w-4" aria-hidden="true" />
                                </span>
                                <input
                                    id="invoice-search"
                                    type="search"
                                    wire:model.live.debounce.300ms="search"
                                    placeholder="{{ __('Search by number or description…') }}"
                                    class="w-full rounded-lg border-brand-ink/15 bg-white py-2 ps-9 pe-3 text-sm text-brand-ink placeholder:text-brand-mist shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                                />
                            </div>
                        </div>
                    </div>

                    @if ($rows->isEmpty())
                        <div class="px-6 py-12 text-center sm:px-7">
                            <span class="mx-auto inline-flex h-10 w-10 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                                <x-heroicon-o-inbox class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <p class="mt-3 text-sm font-medium text-brand-ink">
                                {{ $search !== '' ? __('No invoices match this search.') : __('No invoices found.') }}
                            </p>
                            @if ($search !== '')
                                <button type="button" wire:click="$set('search', '')" class="mt-2 text-xs font-semibold text-brand-sage hover:text-brand-ink">{{ __('Clear search') }}</button>
                            @endif
                        </div>
                    @else
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-brand-ink/5 text-left text-sm">
                                <thead class="bg-brand-sand/35 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">
                                    <tr>
                                        @if ($columns['number'])
                                            <th scope="col" class="px-6 py-2 sm:px-7">
                                                <button type="button" wire:click="sortBy('number')" class="inline-flex items-center gap-1 font-semibold text-brand-moss hover:text-brand-ink">
                                                    {{ __('Number') }}
                                                    <span aria-hidden="true" class="opacity-70">⇅</span>
                                                </button>
                                            </th>
                                        @endif
                                        @if ($columns['description'])
                                            <th scope="col" class="px-4 py-2">
                                                <button type="button" wire:click="sortBy('description')" class="inline-flex items-center gap-1 font-semibold text-brand-moss hover:text-brand-ink">
                                                    {{ __('Description') }}
                                                    <span aria-hidden="true" class="opacity-70">⇅</span>
                                                </button>
                                            </th>
                                        @endif
                                        @if ($columns['status'])
                                            <th scope="col" class="px-4 py-2">{{ __('Status') }}</th>
                                        @endif
                                        @if ($columns['total'])
                                            <th scope="col" class="px-4 py-2 text-right">
                                                <button type="button" wire:click="sortBy('total')" class="inline-flex items-center gap-1 font-semibold text-brand-moss hover:text-brand-ink">
                                                    {{ __('Total') }}
                                                    <span aria-hidden="true" class="opacity-70">⇅</span>
                                                </button>
                                            </th>
                                        @endif
                                        @if ($columns['date'])
                                            <th scope="col" class="px-4 py-2">
                                                <button type="button" wire:click="sortBy('date')" class="inline-flex items-center gap-1 font-semibold text-brand-moss hover:text-brand-ink">
                                                    {{ __('Date') }}
                                                    <span aria-hidden="true" class="opacity-70">⇅</span>
                                                </button>
                                            </th>
                                        @endif
                                        @if ($columns['actions'])
                                            <th scope="col" class="px-6 py-2 text-end sm:px-7">{{ __('Actions') }}</th>
                                        @endif
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-brand-ink/5 bg-white">
                                    @foreach ($rows as $row)
                                        @php
                                            $status = strtolower((string) ($row['status'] ?? ''));
                                            $statusClasses = match (true) {
                                                in_array($status, ['paid', 'succeeded'], true) => 'border-emerald-200 bg-emerald-50 text-emerald-700',
                                                in_array($status, ['open', 'draft'], true) => 'border-sky-200 bg-sky-50 text-sky-700',
                                                in_array($status, ['uncollectible', 'void', 'failed'], true) => 'border-red-200 bg-red-50 text-red-700',
                                                default => 'border-brand-ink/10 bg-brand-sand/40 text-brand-moss',
                                            };
                                        @endphp
                                        <tr class="transition-colors hover:bg-brand-sand/15">
                                            @if ($columns['number'])
                                                <td class="whitespace-nowrap px-6 py-3 font-mono text-xs text-brand-ink sm:px-7">{{ $row['number'] }}</td>
                                            @endif
                                            @if ($columns['description'])
                                                <td class="max-w-md px-4 py-3 text-brand-ink">{{ $row['description'] }}</td>
                                            @endif
                                            @if ($columns['status'])
                                                <td class="whitespace-nowrap px-4 py-3">
                                                    <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $statusClasses }}">
                                                        {{ $row['status_label'] }}
                                                    </span>
                                                </td>
                                            @endif
                                            @if ($columns['total'])
                                                <td class="whitespace-nowrap px-4 py-3 text-right font-mono tabular-nums font-semibold text-brand-ink">{{ $row['total'] }}</td>
                                            @endif
                                            @if ($columns['date'])
                                                <td class="whitespace-nowrap px-4 py-3 font-mono tabular-nums text-brand-moss">{{ $row['date']->format('Y-m-d H:i') }}</td>
                                            @endif
                                            @if ($columns['actions'])
                                                <td class="whitespace-nowrap px-6 py-3 text-end sm:px-7">
                                                    @if (! empty($row['pdf_url']))
                                                        <a
                                                            href="{{ $row['pdf_url'] }}"
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            class="inline-flex items-center gap-1.5 text-xs font-semibold text-brand-sage hover:text-brand-ink"
                                                        >
                                                            @if (! empty($row['is_pdf']))
                                                                <x-heroicon-o-arrow-down-tray class="h-4 w-4 shrink-0" aria-hidden="true" />
                                                                {{ __('PDF') }}
                                                            @else
                                                                <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4 shrink-0" aria-hidden="true" />
                                                                {{ __('View') }}
                                                            @endif
                                                        </a>
                                                    @else
                                                        <span class="text-xs text-brand-mist">—</span>
                                                    @endif
                                                </td>
                                            @endif
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        {{-- Pagination footer. Sandy strip mirroring the
                             toolbar — page selector on the left, page count
                             centered, pagination links on the right. --}}
                        <div class="flex flex-col items-stretch gap-4 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4 sm:flex-row sm:items-center sm:justify-between sm:gap-8 sm:px-7">
                            <label class="inline-flex items-center gap-2 text-xs text-brand-moss" for="per-page">
                                <span class="whitespace-nowrap">{{ __('Rows per page') }}</span>
                                <select
                                    id="per-page"
                                    wire:model.live="perPage"
                                    class="rounded-lg border-brand-ink/15 bg-white py-1.5 pl-3 pr-8 text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                                >
                                    @foreach ([10, 15, 25, 50] as $n)
                                        <option value="{{ $n }}">{{ $n }}</option>
                                    @endforeach
                                </select>
                            </label>
                            @if ($rows->hasPages())
                                <div class="flex-1">
                                    {{ $rows->links() }}
                                </div>
                            @else
                                <span class="text-end text-xs tabular-nums text-brand-moss">{{ trans_choice(':n result|:n results', $rows->total(), ['n' => $rows->total()]) }}</span>
                            @endif
                        </div>
                    @endif
                </section>
            @endif
        </x-organization-shell>
    </div>
</div>
