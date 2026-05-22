<?php

declare(strict_types=1);

namespace Tests\Unit\Services\DigitalOceanServiceDnsTest;
use App\Services\DigitalOceanService;
use Illuminate\Support\Facades\Http;
test('it fetches domain when present in account', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/domains/example.com' => Http::response([
            'domain' => [
                'name' => 'example.com',
                'ttl' => 1800,
            ],
        ], 200),
    ]);

    $service = new DigitalOceanService('dop_v1_test');
    $payload = $service->fetchDomain('example.com');

    expect($payload)->not->toBeNull();
    expect($payload['name'])->toBe('example.com');
    expect($service->domainExistsInAccount('example.com'))->toBeTrue();
});
test('it returns null when domain missing in account', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/domains/unknown.test' => Http::response(['message' => 'Not found'], 404),
    ]);

    $service = new DigitalOceanService('dop_v1_test');
    expect($service->fetchDomain('unknown.test'))->toBeNull();
    expect($service->domainExistsInAccount('unknown.test'))->toBeFalse();
});
test('it can find a matching domain record', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/domains/dply.cc/records*' => Http::response([
            'domain_records' => [
                [
                    'id' => 42,
                    'type' => 'A',
                    'name' => 'preview-app',
                    'data' => '203.0.113.10',
                ],
            ],
        ], 200),
    ]);

    $record = (new DigitalOceanService('dop_v1_test'))
        ->findDomainRecord('dply.cc', 'A', 'preview-app', '203.0.113.10');

    expect($record)->not->toBeNull();
    expect($record['id'])->toBe(42);
});
test('it falls back to local filtering when api name filter misses existing record', function () {
    // The API's name filter "misses" — the filtered call (type/name in the
    // query) returns nothing, so findDomainRecord falls back to the full,
    // unfiltered listing. Match on the query rather than the exact URL so
    // pagination params (per_page/page) don't break the fake.
    Http::fake([
        'https://api.digitalocean.com/v2/domains/dply.cc/records*' => function ($request) {
            if (str_contains($request->url(), 'name=preview-app')) {
                return Http::response(['domain_records' => []], 200);
            }

            return Http::response([
                'domain_records' => [
                    [
                        'id' => 77,
                        'type' => 'A',
                        'name' => 'preview-app',
                        'data' => '203.0.113.10',
                    ],
                ],
            ], 200);
        },
    ]);

    $record = (new DigitalOceanService('dop_v1_test'))
        ->findDomainRecord('dply.cc', 'A', 'preview-app', '203.0.113.10');

    expect($record)->not->toBeNull();
    expect($record['id'])->toBe(77);
});
test('it follows pagination when listing domain records', function () {
    // DO returns DNS records in pages. getDomainRecords must walk
    // links.pages.next — a zone truncated to page 1 would hide records
    // that conflict-purge logic needs to see before a CNAME create.
    Http::fake([
        'https://api.digitalocean.com/v2/domains/dply.cc/records*' => function ($request) {
            if ((int) ($request['page'] ?? 1) === 2) {
                return Http::response([
                    'domain_records' => [
                        ['id' => 2, 'type' => 'A', 'name' => 'second', 'data' => '203.0.113.2'],
                    ],
                ], 200);
            }

            return Http::response([
                'domain_records' => [
                    ['id' => 1, 'type' => 'A', 'name' => 'first', 'data' => '203.0.113.1'],
                ],
                'links' => ['pages' => ['next' => 'https://api.digitalocean.com/v2/domains/dply.cc/records?page=2']],
            ], 200);
        },
    ]);

    $records = (new DigitalOceanService('dop_v1_test'))->getDomainRecords('dply.cc');

    expect($records)->toHaveCount(2);
    expect(array_map(static fn ($r) => $r['id'], $records))->toBe([1, 2]);
});
test('it can create a domain record', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/domains/dply.cc/records' => Http::response([
            'domain_record' => [
                'id' => 73,
                'type' => 'A',
                'name' => 'preview-app',
                'data' => '203.0.113.10',
            ],
        ], 201),
    ]);

    $record = (new DigitalOceanService('dop_v1_test'))
        ->createDomainRecord('dply.cc', 'A', 'preview-app', '203.0.113.10');

    expect($record['id'])->toBe(73);

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.digitalocean.com/v2/domains/dply.cc/records'
            && $request['type'] === 'A'
            && $request['name'] === 'preview-app'
            && $request['data'] === '203.0.113.10';
    });
});
test('it can delete a domain record', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/domains/dply.cc/records/73' => Http::response([], 204),
    ]);

    (new DigitalOceanService('dop_v1_test'))
        ->deleteDomainRecord('dply.cc', 73);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.digitalocean.com/v2/domains/dply.cc/records/73');
});
