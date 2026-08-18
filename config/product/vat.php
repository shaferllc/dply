<?php

/**
 * EU VAT helpers: format checks use the national identifier (after the 2-letter prefix).
 * VIES uses country codes GR→EL mapping when calling the Commission service.
 *
 * @see https://ec.europa.eu/taxation_customs/vies/faq.html
 */
return [

    'vies_enabled' => env('VAT_VIES_ENABLED', true),

    'vies_timeout_seconds' => (int) env('VAT_VIES_TIMEOUT', 8),

    'vies_endpoint' => 'https://ec.europa.eu/taxation_customs/vies/services/checkVatService',

    /**
     * Country codes that participate in EU VIES (including XI — Northern Ireland).
     * GR is accepted from users but mapped to EL for VIES requests.
     */
    'vies_country_codes' => [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI',
        'FR', 'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL',
        'PL', 'PT', 'RO', 'SE', 'SI', 'SK', 'XI',
    ],

    /**
     * Regex for the national portion only (after ISO-3166 alpha-2), case-insensitive.
     * Patterns are pragmatic; edge cases may still pass/fail VIES.
     */
    'national_patterns' => [
        'AT' => 'U\d{8}',
        'BE' => '\d{10}',
        'BG' => '\d{9,10}',
        'CY' => '\d{8}[A-Z]',
        'CZ' => '\d{8,10}',
        'DE' => '\d{9}',
        'DK' => '\d{8}',
        'EE' => '\d{9}',
        'EL' => '\d{9}',
        'ES' => '[A-Z0-9]\d{7}[A-Z0-9]|[A-Z]\d{8}',
        'FI' => '\d{8}',
        'FR' => '[A-HJ-NP-Z0-9]{2}\d{9}',
        'GR' => '\d{9}',
        'HR' => '\d{11}',
        'HU' => '\d{8}',
        'IE' => '[0-9]{7}[A-Z]{1,2}|[0-9][A-Z\*\+][0-9]{5}[A-Z]',
        'IT' => '\d{11}',
        'LT' => '(\d{9}|\d{12})',
        'LU' => '\d{8}',
        'LV' => '\d{11}',
        'MT' => '\d{8}',
        'NL' => '\d{9}B\d{2}',
        'PL' => '\d{10}',
        'PT' => '\d{9}',
        'RO' => '\d{2,10}',
        'SE' => '\d{12}',
        'SI' => '\d{8}',
        'SK' => '\d{10}',
        'XI' => '\d{9}',
    ],

    /**
     * Prefixes accepted for Stripe Customer tax IDs (eu_vat / gb_vat).
     */
    'stripe_eu_vat_prefixes' => [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI',
        'FR', 'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL',
        'PL', 'PT', 'RO', 'SE', 'SI', 'SK', 'XI',
    ],

];
