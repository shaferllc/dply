<?php

declare(strict_types=1);

namespace Tests\Feature\Services\WordPress\Advisories;

use App\Services\WordPress\Advisories\WordfenceIntelligenceProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WordfenceIntelligenceProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_returns_empty_list_when_api_returns_no_vulnerabilities(): void
    {
        Http::fake([
            'wordfence.com/*' => Http::response(['vulnerabilities' => []], 200),
        ]);

        $advisories = (new WordfenceIntelligenceProvider)->forPlugin('akismet', '5.3');

        $this->assertSame([], $advisories);
    }

    public function test_maps_wordfence_record_to_advisory_value_object(): void
    {
        Http::fake([
            'wordfence.com/*' => Http::response([
                'vulnerabilities' => [
                    [
                        'id' => 'wfi-12345',
                        'title' => 'Reflected XSS in Yoast SEO',
                        'cvss' => ['rating' => 'High'],
                        'references' => [
                            'cve' => ['CVE-2024-1234'],
                            'url' => ['https://wordfence.com/threat-intel/vulnerabilities/wfi-12345'],
                        ],
                        'patched' => ['versions' => ['21.6']],
                    ],
                ],
            ], 200),
        ]);

        $advisories = (new WordfenceIntelligenceProvider)->forPlugin('wordpress-seo', '21.5');

        $this->assertCount(1, $advisories);
        $advisory = $advisories[0];
        $this->assertSame('wfi-12345', $advisory->id);
        $this->assertSame('Reflected XSS in Yoast SEO', $advisory->title);
        $this->assertSame('high', $advisory->severity);
        $this->assertSame('CVE-2024-1234', $advisory->cve);
        $this->assertSame('21.6', $advisory->patchedVersion);
        $this->assertStringContainsString('wfi-12345', (string) $advisory->url);
    }

    public function test_returns_empty_on_http_error_and_does_not_throw(): void
    {
        Http::fake([
            'wordfence.com/*' => Http::response(null, 503),
        ]);

        $advisories = (new WordfenceIntelligenceProvider)->forPlugin('akismet', '5.3');

        $this->assertSame([], $advisories);
    }

    public function test_caches_response_for_24_hours(): void
    {
        $callCount = 0;
        Http::fake(function () use (&$callCount) {
            $callCount++;

            return Http::response(['vulnerabilities' => []], 200);
        });

        $provider = new WordfenceIntelligenceProvider;
        $provider->forPlugin('akismet', '5.3');
        $provider->forPlugin('akismet', '5.3');
        $provider->forPlugin('akismet', '5.3');

        $this->assertSame(1, $callCount, 'Identical lookups must hit the cache, not re-call the API.');
    }

    public function test_distinct_versions_use_distinct_cache_keys(): void
    {
        $callCount = 0;
        Http::fake(function () use (&$callCount) {
            $callCount++;

            return Http::response(['vulnerabilities' => []], 200);
        });

        $provider = new WordfenceIntelligenceProvider;
        $provider->forPlugin('akismet', '5.3');
        $provider->forPlugin('akismet', '5.4');

        $this->assertSame(2, $callCount);
    }

    public function test_provider_name(): void
    {
        $this->assertSame('Wordfence Intelligence', (new WordfenceIntelligenceProvider)->name());
    }
}
