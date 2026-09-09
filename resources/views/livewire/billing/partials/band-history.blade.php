{{-- Band 03 — Invoices. This is where invoices live: the receipt half of the
     plan + card above, rather than a route of their own. The Invoices page
     survives as "All invoices" for orgs with years of history. --}}
@php
    $invoices = $this->recentInvoices;
@endphp

<section class="border-b border-brand-ink/10 last:border-b-0">
    <x-workspace-panel-head
        dense
        class="border-b border-brand-ink/10"
        icon="heroicon-o-document-text"
        :title="__('Invoices')"
        :note="__('Receipts Stripe issued for this organization. Newest first.')"
    >
        <x-slot:actions>
                <a
                    href="{{ route('billing.invoices', $organization) }}"
                    wire:navigate
                    class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40"
                >
                    {{ __('All invoices') }}
                    <x-heroicon-m-chevron-right class="h-3 w-3 shrink-0 opacity-70" aria-hidden="true" />
                </a>
                <button type="button" wire:click="portal" class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40">
                    {{ __('Stripe portal') }}
                    <x-heroicon-m-arrow-up-right class="h-3 w-3 shrink-0" aria-hidden="true" />
                </button>
        </x-slot:actions>
    </x-workspace-panel-head>

    @if ($invoices->isEmpty())
        <div class="px-3 py-6 text-center sm:px-4">
            <p class="text-xs leading-relaxed text-brand-moss">
                {{ __('No invoices yet — they appear here once the trial ends and a card is on file.') }}
            </p>
        </div>
    @else
        {{-- What / when / how much / paid, and the PDF itself — the old row gave
             a date and a number and sent "View" back to another list. --}}
        <ul class="divide-y divide-brand-ink/5">
            @foreach ($invoices as $invoice)
                @php
                    $status = strtolower((string) ($invoice['status'] ?? ''));
                    $statusClasses = match (true) {
                        in_array($status, ['paid', 'succeeded'], true) => 'border-emerald-200 bg-emerald-50 text-emerald-700',
                        in_array($status, ['open', 'draft'], true) => 'border-sky-200 bg-sky-50 text-sky-700',
                        in_array($status, ['uncollectible', 'void', 'failed'], true) => 'border-red-200 bg-red-50 text-red-700',
                        default => 'border-brand-ink/10 bg-brand-sand/40 text-brand-moss',
                    };
                @endphp
                <li wire:key="invoice-{{ $invoice['id'] }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2 sm:px-4">
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium text-brand-ink" title="{{ $invoice['description'] }}">{{ $invoice['description'] }}</span>
                        <span class="mt-0.5 block truncate font-mono text-2xs text-brand-mist">{{ $invoice['number'] }} · {{ $invoice['date']->format('Y-m-d') }}</span>
                    </span>
                    <span class="inline-flex shrink-0 items-center rounded border px-1.5 py-px text-2xs font-semibold uppercase tracking-wide {{ $statusClasses }}">{{ $invoice['status_label'] }}</span>
                    <span class="w-20 shrink-0 text-right font-mono text-sm font-semibold tabular-nums text-brand-ink">{{ $invoice['total'] }}</span>
                    @if (! empty($invoice['pdf_url']))
                        <a href="{{ $invoice['pdf_url'] }}" target="_blank" rel="noopener noreferrer" class="shrink-0 text-xs font-semibold text-brand-sage hover:text-brand-ink">
                            {{ ! empty($invoice['is_pdf']) ? __('PDF') : __('View') }}
                        </a>
                    @else
                        <span class="shrink-0 text-xs text-brand-mist">—</span>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</section>
