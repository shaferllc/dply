<?php

namespace App\Modules\Billing\Services;

use App\Models\Organization;
use Illuminate\Support\Collection;
use Laravel\Cashier\Invoice;
use Throwable;

/**
 * Stripe invoices flattened into display rows, shared by the Invoices page and
 * the invoice section on Billing & plan — the mapping (description fallbacks,
 * status label, PDF vs hosted URL) is the same in both and was worth one home.
 */
class StripeInvoiceRows
{
    /**
     * @return Collection<int, array<string, mixed>>
     */
    public static function for(Organization $organization, int $limit = 100, ?string $timezone = null): Collection
    {
        if (! $organization->hasStripeId()) {
            return collect();
        }

        try {
            $invoices = $organization->invoicesIncludingPending(['limit' => $limit]);
        } catch (Throwable) {
            // Stripe being down must not take the billing page with it.
            return collect();
        }

        $tz = $timezone ?: (auth()->user()->timezone ?? config('app.timezone', 'UTC'));

        /** @var Collection<int, array<string, mixed>> $rows */
        $rows = $invoices->map(function (Invoice $invoice) use ($tz): array {
            $stripe = $invoice->asStripeInvoice();
            $pdfUrl = $stripe->invoice_pdf ?? $stripe->hosted_invoice_url;

            return [
                'id' => $stripe->id,
                'number' => $stripe->number ?? $stripe->id,
                'description' => self::describe($invoice),
                'status' => (string) ($stripe->status ?? 'unknown'),
                'status_label' => self::statusLabel((string) ($stripe->status ?? '')),
                'total' => $invoice->total(),
                'date' => $invoice->date($tz),
                'pdf_url' => $pdfUrl,
                'is_pdf' => ! empty($stripe->invoice_pdf),
            ];
        });

        return $rows;
    }

    public static function describe(Invoice $invoice): string
    {
        $stripe = $invoice->asStripeInvoice();
        if (! empty($stripe->description)) {
            return $stripe->description;
        }

        $lines = $stripe->lines->data ?? [];
        if ($lines === []) {
            return '—';
        }

        $first = $lines[0];
        if (! empty($first->description)) {
            return $first->description;
        }

        if (isset($first->plan) && is_object($first->plan) && ! empty($first->plan->nickname)) {
            return (string) $first->plan->nickname;
        }

        if (isset($first->price) && is_object($first->price) && ! empty($first->price->nickname)) {
            return (string) $first->price->nickname;
        }

        return __('Subscription invoice');
    }

    public static function statusLabel(string $status): string
    {
        return $status === ''
            ? '—'
            : ucfirst(str_replace('_', ' ', $status));
    }
}
