<?php

namespace App\Services\Billing;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Collects non-blocking VAT insights (format + EU VIES) for profile billing fields.
 *
 * @return list<string>
 */
class VatInsightService
{
    /**
     * @return list<string>
     */
    public function collectSoftWarnings(?string $vatNumber): array
    {
        $normalized = $this->normalize($vatNumber ?? '');
        if ($normalized === '') {
            return [];
        }

        $warnings = [];

        $parsed = $this->parseCountryAndNational($normalized);
        if ($parsed === null) {
            $warnings[] = __('The VAT number should start with a two-letter country code (for example NL or DE).');

            return $warnings;
        }

        [$country, $national] = $parsed;

        $patternKey = $this->patternCountryKey($country);
        $patterns = config('vat.national_patterns', []);

        $formatMismatch = false;
        if ($this->isViesCountryCode($country)) {
            $regex = $patterns[$patternKey] ?? null;
            if ($regex !== null && ! preg_match('#^'.$regex.'$#iu', $national)) {
                $formatMismatch = true;
                $warnings[] = __('This VAT number does not match the usual format for :country. Double-check the country code and digits.', [
                    'country' => $country,
                ]);
            }
        }

        if (! config('vat.vies_enabled', true)) {
            return $warnings;
        }

        if (! $this->isViesCountryCode($country)) {
            return $warnings;
        }

        if ($formatMismatch) {
            return $warnings;
        }

        $viesCountry = $this->toViesCountryCode($country);
        $viesResult = $this->requestVies($viesCountry, $national);

        if ($viesResult['status'] === 'unavailable') {
            $warnings[] = __('We could not reach the EU VAT registry right now. Your VAT number was saved; try verifying again later.');

            return $warnings;
        }

        if ($viesResult['status'] === 'invalid') {
            $warnings[] = __('The EU VAT registry did not recognize this VAT number. It was still saved—confirm the number with your national tax authority if needed.');

            return $warnings;
        }

        return $warnings;
    }

    /**
     * Blocking validation before persisting a VAT number: EU national format (when applicable) and GB digits.
     * Returns null when valid or empty; otherwise a translated error message.
     */
    public function blockingValidationMessage(?string $raw): ?string
    {
        $normalized = $this->normalize($raw ?? '');
        if ($normalized === '') {
            return null;
        }

        $parsed = $this->parseCountryAndNational($normalized);
        if ($parsed === null) {
            return __('The VAT number must start with a two-letter country code (for example NL, DE, or FR) followed by your national number.');
        }

        [$country, $national] = $parsed;

        $allowed = array_values(array_unique(array_merge(
            config('vat.stripe_eu_vat_prefixes', []),
            ['GB'],
        )));

        if (! in_array($country, $allowed, true)) {
            return __('Use an EU VAT number or a GB VAT number. The first two letters must be a supported country code.');
        }

        if ($country === 'GB') {
            if (! preg_match('/^\d{9}(?:\d{3})?$/', $national)) {
                return __('Enter a valid GB VAT number: 9 or 12 digits after GB.');
            }

            return null;
        }

        if (! $this->isViesCountryCode($country)) {
            return null;
        }

        $patterns = config('vat.national_patterns', []);
        $patternKey = $this->patternCountryKey($country);
        $regex = $patterns[$patternKey] ?? $patterns[$country] ?? null;
        if ($regex !== null && ! preg_match('#^'.$regex.'$#iu', $national)) {
            return __('This VAT number does not match the usual format for :country.', [
                'country' => $country,
            ]);
        }

        return null;
    }

    private function normalize(string $raw): string
    {
        $trimmed = strtoupper(preg_replace('/[\s.\-\x{00A0}]+/u', '', $raw) ?? '');

        return $trimmed;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function parseCountryAndNational(string $normalized): ?array
    {
        if (strlen($normalized) < 4) {
            return null;
        }

        $country = substr($normalized, 0, 2);
        if (! preg_match('/^[A-Z]{2}$/', $country)) {
            return null;
        }

        $national = substr($normalized, 2);
        if ($national === '') {
            return null;
        }

        return [$country, $national];
    }

    private function patternCountryKey(string $country): string
    {
        return $country === 'GR' ? 'EL' : $country;
    }

    private function isViesCountryCode(string $country): bool
    {
        return in_array($country, config('vat.vies_country_codes', []), true);
    }

    private function toViesCountryCode(string $country): string
    {
        return $country === 'GR' ? 'EL' : $country;
    }

    /**
     * @return array{status: 'valid'|'invalid'|'unavailable'}
     */
    private function requestVies(string $countryCode, string $vatNumber): array
    {
        $endpoint = (string) config('vat.vies_endpoint');
        $timeout = max(1, (int) config('vat.vies_timeout_seconds', 8));

        $safeCountry = htmlspecialchars($countryCode, ENT_XML1 | ENT_COMPAT, 'UTF-8');
        $safeNumber = htmlspecialchars($vatNumber, ENT_XML1 | ENT_COMPAT, 'UTF-8');

        $body = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:tns="urn:ec.europa.eu:taxud:vies:services:checkVat:types">
  <soap:Body>
    <tns:checkVat>
      <tns:countryCode>{$safeCountry}</tns:countryCode>
      <tns:vatNumber>{$safeNumber}</tns:vatNumber>
    </tns:checkVat>
  </soap:Body>
</soap:Envelope>
XML;

        try {
            $response = Http::timeout($timeout)
                ->withHeaders([
                    'Content-Type' => 'text/xml; charset=utf-8',
                    'SOAPAction' => '""',
                ])
                ->withBody($body, 'text/xml; charset=utf-8')
                ->post($endpoint);
        } catch (Throwable $e) {
            Log::notice('vat.vies_request_failed', [
                'country' => $countryCode,
                'message' => $e->getMessage(),
            ]);

            return ['status' => 'unavailable'];
        }

        if (! $response->successful()) {
            Log::notice('vat.vies_http_error', [
                'country' => $countryCode,
                'status' => $response->status(),
            ]);

            return ['status' => 'unavailable'];
        }

        $xml = $response->body();

        if (preg_match('/<faultstring>\s*([^<]+)\s*<\/faultstring>/i', $xml, $fault)) {
            Log::notice('vat.vies_fault', [
                'country' => $countryCode,
                'fault' => trim($fault[1]),
            ]);

            return ['status' => 'unavailable'];
        }

        if (preg_match('/<valid>\s*(true|false)\s*<\/valid>/i', $xml, $m)) {
            return strtolower($m[1]) === 'true'
                ? ['status' => 'valid']
                : ['status' => 'invalid'];
        }

        Log::notice('vat.vies_unparseable_response', ['country' => $countryCode]);

        return ['status' => 'unavailable'];
    }
}
