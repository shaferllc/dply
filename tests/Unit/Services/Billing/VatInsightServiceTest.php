<?php

namespace Tests\Unit\Services\Billing;

use App\Services\Billing\VatInsightService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VatInsightServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('vat.vies_enabled', true);
    }

    public function test_empty_vat_yields_no_warnings(): void
    {
        Http::fake();

        $warnings = app(VatInsightService::class)->collectSoftWarnings(null);

        $this->assertSame([], $warnings);
        Http::assertNothingSent();
    }

    public function test_short_input_warns_without_calling_vies(): void
    {
        Http::fake();

        $warnings = app(VatInsightService::class)->collectSoftWarnings('N');

        $this->assertCount(1, $warnings);
        Http::assertNothingSent();
    }

    public function test_skips_vies_when_eu_format_does_not_match(): void
    {
        Http::fake();

        $warnings = app(VatInsightService::class)->collectSoftWarnings('NL123');

        $this->assertNotEmpty($warnings);
        Http::assertNothingSent();
    }

    public function test_vies_invalid_warns(): void
    {
        Http::fake([
            '*' => Http::response(
                '<Envelope xmlns=""><soap:Body><valid>false</valid></soap:Body></Envelope>',
                200,
                ['Content-Type' => 'text/xml']
            ),
        ]);

        $warnings = app(VatInsightService::class)->collectSoftWarnings('NL123456789B01');

        $this->assertTrue(collect($warnings)->contains(fn (string $w) => str_contains($w, 'EU VAT registry')));
        Http::assertSentCount(1);
    }

    public function test_vies_fault_warns_unavailable(): void
    {
        Http::fake([
            '*' => Http::response(
                '<Envelope><soap:Body><soap:Fault><faultstring>MS_UNAVAILABLE</faultstring></soap:Fault></soap:Body></Envelope>',
                200,
                ['Content-Type' => 'text/xml']
            ),
        ]);

        $warnings = app(VatInsightService::class)->collectSoftWarnings('NL123456789B01');

        $this->assertTrue(collect($warnings)->contains(fn (string $w) => str_contains($w, 'EU VAT registry')));
        Http::assertSentCount(1);
    }

    public function test_vies_valid_yields_no_warnings(): void
    {
        Http::fake([
            '*' => Http::response(
                '<Envelope><soap:Body><checkVatResponse><valid>true</valid></checkVatResponse></soap:Body></Envelope>',
                200,
                ['Content-Type' => 'text/xml']
            ),
        ]);

        $warnings = app(VatInsightService::class)->collectSoftWarnings('NL123456789B01');

        $this->assertSame([], $warnings);
    }

    public function test_respects_vies_disabled_flag(): void
    {
        Config::set('vat.vies_enabled', false);
        Http::fake();

        $warnings = app(VatInsightService::class)->collectSoftWarnings('NL123456789B01');

        $this->assertSame([], $warnings);
        Http::assertNothingSent();
    }
}
