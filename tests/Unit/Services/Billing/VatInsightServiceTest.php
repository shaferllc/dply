<?php


namespace Tests\Unit\Services\Billing\VatInsightServiceTest;
use App\Services\Billing\VatInsightService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Config::set('vat.vies_enabled', true);
});

test('empty vat yields no warnings', function () {
    Http::fake();

    $warnings = app(VatInsightService::class)->collectSoftWarnings(null);

    expect($warnings)->toBe([]);
    Http::assertNothingSent();
});

test('short input warns without calling vies', function () {
    Http::fake();

    $warnings = app(VatInsightService::class)->collectSoftWarnings('N');

    expect($warnings)->toHaveCount(1);
    Http::assertNothingSent();
});

test('skips vies when eu format does not match', function () {
    Http::fake();

    $warnings = app(VatInsightService::class)->collectSoftWarnings('NL123');

    expect($warnings)->not->toBeEmpty();
    Http::assertNothingSent();
});

test('vies invalid warns', function () {
    Http::fake([
        '*' => Http::response(
            '<Envelope xmlns=""><soap:Body><valid>false</valid></soap:Body></Envelope>',
            200,
            ['Content-Type' => 'text/xml']
        ),
    ]);

    $warnings = app(VatInsightService::class)->collectSoftWarnings('NL123456789B01');

    expect(collect($warnings)->contains(fn (string $w) => str_contains($w, 'EU VAT registry')))->toBeTrue();
    Http::assertSentCount(1);
});

test('vies fault warns unavailable', function () {
    Http::fake([
        '*' => Http::response(
            '<Envelope><soap:Body><soap:Fault><faultstring>MS_UNAVAILABLE</faultstring></soap:Fault></soap:Body></Envelope>',
            200,
            ['Content-Type' => 'text/xml']
        ),
    ]);

    $warnings = app(VatInsightService::class)->collectSoftWarnings('NL123456789B01');

    expect(collect($warnings)->contains(fn (string $w) => str_contains($w, 'EU VAT registry')))->toBeTrue();
    Http::assertSentCount(1);
});

test('vies valid yields no warnings', function () {
    Http::fake([
        '*' => Http::response(
            '<Envelope><soap:Body><checkVatResponse><valid>true</valid></checkVatResponse></soap:Body></Envelope>',
            200,
            ['Content-Type' => 'text/xml']
        ),
    ]);

    $warnings = app(VatInsightService::class)->collectSoftWarnings('NL123456789B01');

    expect($warnings)->toBe([]);
});

test('respects vies disabled flag', function () {
    Config::set('vat.vies_enabled', false);
    Http::fake();

    $warnings = app(VatInsightService::class)->collectSoftWarnings('NL123456789B01');

    expect($warnings)->toBe([]);
    Http::assertNothingSent();
});