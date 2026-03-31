<?php

namespace App\Listeners;

use App\Services\Referrals\ReferralConversionService;
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
