<?php

declare(strict_types=1);

namespace App\Services\Billing;

/**
 * Extracts a monthly amount from free-form server cost notes (manual entry
 * or provider-pull formatted strings like "~$12.00/mo · Hetzner cx22 …").
 */
final class ServerMonthlyCostNoteParser
{
    /**
     * @return array{amount: float, currency: string}|null
     */
    public function parse(?string $note): ?array
    {
        if ($note === null || trim($note) === '') {
            return null;
        }

        $text = trim($note);

        if (preg_match('/~\$(\d+(?:\.\d+)?)\s*\/\s*mo/i', $text, $matches) === 1) {
            return ['amount' => (float) $matches[1], 'currency' => 'USD'];
        }

        if (preg_match('/\$(\d+(?:\.\d+)?)\s*\/\s*mo/i', $text, $matches) === 1) {
            return ['amount' => (float) $matches[1], 'currency' => 'USD'];
        }

        if (preg_match('/€(\d+(?:\.\d+)?)\s*\/\s*mo/i', $text, $matches) === 1) {
            return ['amount' => (float) $matches[1], 'currency' => 'EUR'];
        }

        if (preg_match('/\$(\d+(?:\.\d+)?)/', $text, $matches) === 1) {
            return ['amount' => (float) $matches[1], 'currency' => 'USD'];
        }

        if (preg_match('/€(\d+(?:\.\d+)?)/', $text, $matches) === 1) {
            return ['amount' => (float) $matches[1], 'currency' => 'EUR'];
        }

        return null;
    }

    public function toUsdCents(float $amount, string $currency): int
    {
        if ($currency === 'EUR') {
            $rate = (float) config('subscription.observatory.eur_to_usd_rate', 1.08);

            return (int) round($amount * $rate * 100);
        }

        return (int) round($amount * 100);
    }
}
