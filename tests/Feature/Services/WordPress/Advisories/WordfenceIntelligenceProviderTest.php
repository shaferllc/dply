<?php

declare(strict_types=1);

namespace Tests\Feature\Services\WordPress\Advisories\WordfenceIntelligenceProviderTest;

use App\Services\WordPress\Advisories\WordfenceIntelligenceProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
});
test('returns empty list when api returns no vulnerabilities', function () {
    Http::fake([
        'wordfence.com/*' => Http::response(['vulnerabilities' => []], 200),
    ]);

    $advisories = (new WordfenceIntelligenceProvider)->forPlugin('akismet', '5.3');

    expect($advisories)->toBe([]);
});
test('maps wordfence record to advisory value object', function () {
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

    expect($advisories)->toHaveCount(1);
    $advisory = $advisories[0];
    expect($advisory->id)->toBe('wfi-12345');
    expect($advisory->title)->toBe('Reflected XSS in Yoast SEO');
    expect($advisory->severity)->toBe('high');
    expect($advisory->cve)->toBe('CVE-2024-1234');
    expect($advisory->patchedVersion)->toBe('21.6');
    $this->assertStringContainsString('wfi-12345', (string) $advisory->url);
});
test('returns empty on http error and does not throw', function () {
    Http::fake([
        'wordfence.com/*' => Http::response(null, 503),
    ]);

    $advisories = (new WordfenceIntelligenceProvider)->forPlugin('akismet', '5.3');

    expect($advisories)->toBe([]);
});
test('caches response for 24 hours', function () {
    $callCount = 0;
    Http::fake(function () use (&$callCount) {
        $callCount++;

        return Http::response(['vulnerabilities' => []], 200);
    });

    $provider = new WordfenceIntelligenceProvider;
    $provider->forPlugin('akismet', '5.3');
    $provider->forPlugin('akismet', '5.3');
    $provider->forPlugin('akismet', '5.3');

    expect($callCount)->toBe(1, 'Identical lookups must hit the cache, not re-call the API.');
});
test('distinct versions use distinct cache keys', function () {
    $callCount = 0;
    Http::fake(function () use (&$callCount) {
        $callCount++;

        return Http::response(['vulnerabilities' => []], 200);
    });

    $provider = new WordfenceIntelligenceProvider;
    $provider->forPlugin('akismet', '5.3');
    $provider->forPlugin('akismet', '5.4');

    expect($callCount)->toBe(2);
});
test('provider name', function () {
    expect((new WordfenceIntelligenceProvider)->name())->toBe('Wordfence Intelligence');
});
