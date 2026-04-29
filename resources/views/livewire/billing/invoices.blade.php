<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-organization-shell :organization="$organization" section="invoices">
            <x-livewire-validation-errors />

            <x-breadcrumb-trail :items="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => $organization->name, 'href' => route('organizations.show', $organization), 'icon' => 'building-office-2'],
                ['label' => __('Invoices'), 'icon' => 'document-text'],
            ]" />

            <div class="space-y-8">
                <div class="dply-card overflow-hidden">
                    <div class="grid lg:grid-cols-12 gap-8 p-6 sm:p-8">
                        <div class="lg:col-span-4">
                            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Invoices') }}</h2>
                            <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                                {{ __('Stripe invoices for :org.', ['org' => $organization->name]) }}
                            </p>
                        </div>
                        <div class="lg:col-span-8 flex flex-wrap items-start justify-end gap-3">
                            <a
                                href="{{ route('docs.index') }}"
                                wire:navigate
                                class="inline-flex items-center gap-1.5 rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm font-medium text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40"
                            >
                                <x-heroicon-o-document-text class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                                {{ __('Documentation') }}
                            </a>
                            <x-badge tone="accent" :caps="false" class="text-xs">
                                {{ __('Organization: :name', ['name' => $organization->name]) }}
                            </x-badge>
                        </div>
                    </div>
                </div>

                @if (! $hasStripeCustomer)
                    <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                        {{ __('No Stripe customer is linked to this organization yet. Subscribe to a plan from billing to generate invoices.') }}
                    </div>
                @else
                    <div
                        class="dply-card overflow-hidden border-brand-mist/60"
                        x-data="{ showColumns: false }"
                    >
                        <div class="flex flex-col gap-3 border-b border-brand-mist/60 bg-brand-sand/20 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                            <div class="relative">
                                <button
                                    type="button"
                                    @click="showColumns = !showColumns"
                                    @click.outside="showColumns = false"
                                    class="inline-flex items-center gap-2 rounded-lg border border-brand-mist bg-white px-3 py-2 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40"
                                >
                                    <x-heroicon-o-eye class="h-4 w-4 text-brand-moss" aria-hidden="true" />
                                    {{ __('Columns') }}
                                </button>
                                <div
                                    x-show="showColumns"
                                    x-cloak
                                    x-transition
                                    class="absolute left-0 z-20 mt-1 w-56 dply-flyout-panel py-2"
                                >
                                    <p class="px-3 pb-2 text-xs font-semibold uppercase tracking-wider text-brand-mist">{{ __('Visible columns') }}</p>
                                    @foreach (['number' => __('Number'), 'description' => __('Description'), 'status' => __('Status'), 'total' => __('Total'), 'date' => __('Date'), 'actions' => __('Actions')] as $key => $label)
                                        <label class="flex cursor-pointer items-center gap-2 px-3 py-1.5 text-sm text-brand-ink hover:bg-brand-sand/40">
                                            <input type="checkbox" wire:model.live="columns.{{ $key }}" class="rounded border-brand-mist text-brand-ink" />
                                            {{ $label }}
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                            <div class="w-full sm:max-w-md sm:ml-auto">
                                <label for="invoice-search" class="sr-only">{{ __('Search') }}</label>
                                <input
                                    id="invoice-search"
                                    type="search"
                                    wire:model.live.debounce.300ms="search"
                                    placeholder="{{ __('Search…') }}"
                                    class="w-full rounded-lg border border-brand-mist bg-white px-3 py-2 text-sm text-brand-ink placeholder:text-brand-mist focus:border-brand-sage focus:ring-brand-sage"
                                />
                            </div>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-brand-mist/60 text-left text-sm">
                                <thead class="bg-brand-cream/50 text-xs font-semibold uppercase tracking-wide text-brand-moss">
                                    <tr>
                                        @if ($columns['number'])
                                            <th scope="col" class="px-4 py-3">
                                                <button type="button" wire:click="sortBy('number')" class="inline-flex items-center gap-1 font-semibold text-brand-ink hover:text-brand-sage">
                                                    {{ __('Number') }}
                                                    <span class="text-brand-mist" aria-hidden="true">⇅</span>
                                                </button>
                                            </th>
                                        @endif
                                        @if ($columns['description'])
                                            <th scope="col" class="px-4 py-3">
                                                <button type="button" wire:click="sortBy('description')" class="inline-flex items-center gap-1 font-semibold text-brand-ink hover:text-brand-sage">
                                                    {{ __('Description') }}
                                                    <span class="text-brand-mist" aria-hidden="true">⇅</span>
                                                </button>
                                            </th>
                                        @endif
                                        @if ($columns['status'])
                                            <th scope="col" class="px-4 py-3">{{ __('Status') }}</th>
                                        @endif
                                        @if ($columns['total'])
                                            <th scope="col" class="px-4 py-3">
                                                <button type="button" wire:click="sortBy('total')" class="inline-flex items-center gap-1 font-semibold text-brand-ink hover:text-brand-sage">
                                                    {{ __('Total') }}
                                                    <span class="text-brand-mist" aria-hidden="true">⇅</span>
                                                </button>
                                            </th>
                                        @endif
                                        @if ($columns['date'])
                                            <th scope="col" class="px-4 py-3">
                                                <button type="button" wire:click="sortBy('date')" class="inline-flex items-center gap-1 font-semibold text-brand-ink hover:text-brand-sage">
                                                    {{ __('Date') }}
                                                    <span class="text-brand-mist" aria-hidden="true">⇅</span>
                                                </button>
                                            </th>
                                        @endif
                                        @if ($columns['actions'])
                                            <th scope="col" class="px-4 py-3 text-end">{{ __('Actions') }}</th>
                                        @endif
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-brand-mist/40 bg-white">
                                    @forelse ($rows as $row)
                                        <tr class="hover:bg-brand-sand/20">
                                            @if ($columns['number'])
                                                <td class="whitespace-nowrap px-4 py-3 font-mono text-xs text-brand-ink">{{ $row['number'] }}</td>
                                            @endif
                                            @if ($columns['description'])
                                                <td class="max-w-md px-4 py-3 text-brand-ink">{{ $row['description'] }}</td>
                                            @endif
                                            @if ($columns['status'])
                                                <td class="whitespace-nowrap px-4 py-3">
                                                    <span class="inline-flex rounded-md bg-brand-sand/80 px-2 py-0.5 text-xs font-medium text-brand-ink">
                                                        {{ $row['status_label'] }}
                                                    </span>
                                                </td>
                                            @endif
                                            @if ($columns['total'])
                                                <td class="whitespace-nowrap px-4 py-3 tabular-nums text-brand-ink">{{ $row['total'] }}</td>
                                            @endif
                                            @if ($columns['date'])
                                                <td class="whitespace-nowrap px-4 py-3 text-brand-moss tabular-nums">{{ $row['date']->format('Y-m-d H:i:s') }}</td>
                                            @endif
                                            @if ($columns['actions'])
                                                <td class="whitespace-nowrap px-4 py-3 text-end">
                                                    @if (! empty($row['pdf_url']))
                                                        <a
                                                            href="{{ $row['pdf_url'] }}"
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            class="text-sm font-medium text-brand-sage hover:text-brand-ink"
                                                        >
                                                            {{ ! empty($row['is_pdf']) ? __('View PDF') : __('View invoice') }}
                                                        </a>
                                                    @else
                                                        <span class="text-sm text-brand-mist">—</span>
                                                    @endif
                                                </td>
                                            @endif
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="{{ max(1, collect($columns)->filter()->count()) }}" class="px-4 py-8 text-center text-sm text-brand-moss">
                                                {{ __('No invoices found.') }}
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>

                        @if ($rows->hasPages())
                            <div class="flex flex-col gap-3 border-t border-brand-mist/60 bg-brand-sand/30 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                                <div class="flex items-center gap-2 text-sm text-brand-moss">
                                    <label for="per-page" class="whitespace-nowrap">{{ __('Rows per page') }}</label>
                                    <select
                                        id="per-page"
                                        wire:model.live="perPage"
                                        class="rounded-lg border border-brand-mist bg-white px-2 py-1 text-sm text-brand-ink focus:border-brand-sage focus:ring-brand-sage"
                                    >
                                        @foreach ([10, 15, 25, 50] as $n)
                                            <option value="{{ $n }}">{{ $n }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <p class="text-center text-sm text-brand-moss">
                                    {{ __('Page :current of :last', ['current' => $rows->currentPage(), 'last' => max(1, $rows->lastPage())]) }}
                                </p>
                                <div class="flex justify-end">
                                    {{ $rows->links() }}
                                </div>
                            </div>
                        @else
                            <div class="flex flex-wrap items-center gap-3 border-t border-brand-mist/60 bg-brand-sand/30 px-4 py-3 text-sm text-brand-moss">
                                <span>{{ __('Rows per page') }}</span>
                                <select
                                    wire:model.live="perPage"
                                    class="rounded-lg border border-brand-mist bg-white px-2 py-1 text-sm text-brand-ink"
                                >
                                    @foreach ([10, 15, 25, 50] as $n)
                                        <option value="{{ $n }}">{{ $n }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        </x-organization-shell>
    </div>
</div>
