<?php

namespace App\Modules\Referrals\Listeners;

use App\Modules\Referrals\Services\ReferralConversionService;
use Laravel\Cashier\Events\WebhookReceived;

class ProcessReferralInvoicePayment
{
    public function __construct(
        private ReferralConversionService $conversion,
    ) {}

    public function handle(WebhookReceived $event): void
    {
        if (($event->payload['type'] ?? '') !== 'invoice.payment_succeeded') {
            return;
        }

        $this->conversion->handleInvoicePaymentSucceeded($event->payload);
    }
}
