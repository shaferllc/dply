<?php

declare(strict_types=1);

namespace App\Support\Billing;

/**
 * Reference-rate currency conversion for *display* only.
 *
 * Rates come from `subscription.standard.observatory.currency_rates` and are
 * expressed as USD per 1 unit of the currency (EUR => 1.08 means €1 = $1.08).
 * They are static config, not a live FX quote — everything dply actually
 * charges is USD, and provider invoices settle at the provider's own rate.
 */
final class CurrencyConverter
{
    /** Symbols for the currencies we display; anything else falls back to its code. */
    private const SYMBOLS = [
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'CAD' => 'CA$',
        'AUD' => 'A$',
        'JPY' => '¥',
    ];

    /**
     * @return array<string, float>
     */
    public function rates(): array
    {
        $rates = config('subscription.standard.observatory.currency_rates', []);
        $clean = ['USD' => 1.0];

        if (is_array($rates)) {
            foreach ($rates as $code => $rate) {
                if (is_string($code) && is_numeric($rate) && (float) $rate > 0) {
                    $clean[strtoupper($code)] = (float) $rate;
                }
            }
        }

        return $clean;
    }

    /** USD per 1 unit of $currency; null when we have no rate for it. */
    public function rateFor(string $currency): ?float
    {
        return $this->rates()[strtoupper($currency)] ?? null;
    }

    public function supports(string $currency): bool
    {
        return $this->rateFor($currency) !== null;
    }

    /** @return list<string> */
    public function displayCurrencies(): array
    {
        $configured = config('subscription.standard.observatory.display_currencies', ['USD']);
        $rates = $this->rates();
        $codes = [];

        foreach (is_array($configured) ? $configured : [] as $code) {
            if (! is_string($code)) {
                continue;
            }

            $code = strtoupper($code);
            if (isset($rates[$code]) && ! in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return $codes === [] ? ['USD'] : $codes;
    }

    public function toUsd(float $amount, string $currency): float
    {
        return $amount * ($this->rateFor($currency) ?? 1.0);
    }

    public function fromUsd(float $usd, string $currency): ?float
    {
        $rate = $this->rateFor($currency);

        // Rates are USD-per-unit, so going the other way divides.
        return $rate === null || $rate <= 0.0 ? null : $usd / $rate;
    }

    public function symbol(string $currency): string
    {
        return self::SYMBOLS[strtoupper($currency)] ?? strtoupper($currency).' ';
    }

    public function format(float $amount, string $currency): string
    {
        return $this->symbol($currency).number_format($amount, 2);
    }

    public function formatMonthly(float $amount, string $currency): string
    {
        return $this->format($amount, $currency).'/mo';
    }
}
