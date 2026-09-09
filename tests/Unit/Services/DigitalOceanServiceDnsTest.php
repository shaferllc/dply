<?php

declare(strict_types=1);

namespace Tests\Unit\Services\DigitalOceanServiceDnsTest;

use App\Modules\Providers\Services\DigitalOceanService;
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

// Re-pointing a hostname at a new server used to append a second A record:
// the lookup matched on VALUE, so a changed IP never found the existing
// record and fell through to create. Both addresses then answered for the
// same name and DNS round-robined between the old and new box.
test('upsert replaces the existing record instead of adding a second one', function () {
    $requests = [];

    Http::fake(function ($request) use (&$requests) {
        $requests[] = $request->method().' '.parse_url($request->url(), PHP_URL_PATH);

        if ($request->method() === 'GET') {
            return Http::response(['domain_records' => [
                ['id' => 7, 'type' => 'A', 'name' => '@', 'data' => '146.190.208.196'],
            ]], 200);
        }

        return Http::response(['domain_record' => [
            'id' => 7, 'type' => 'A', 'name' => '@', 'data' => '138.68.45.154',
        ]], 200);
    });

    $record = (new DigitalOceanService('dop_v1_test'))
        ->upsertDomainRecord('tjshafer.com', 'A', '@', '138.68.45.154');

    expect($record['id'])->toBe(7);
    expect($record['data'])->toBe('138.68.45.154');
    expect($requests)->toContain('PUT /v2/domains/tjshafer.com/records/7');
    expect(collect($requests)->filter(fn (string $r) => str_starts_with($r, 'POST')))->toBeEmpty();
});

test('upsert prunes duplicate records left behind by the old append behaviour', function () {
    $deleted = [];

    Http::fake(function ($request) use (&$deleted) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        if ($request->method() === 'DELETE') {
            $deleted[] = basename((string) $path);

            return Http::response([], 204);
        }

        if ($request->method() === 'GET') {
            // The state a zone is left in after the bug: two A records at the
            // apex, one per server the site has ever been pointed at.
            return Http::response(['domain_records' => [
                ['id' => 7, 'type' => 'A', 'name' => '@', 'data' => '146.190.208.196'],
                ['id' => 9, 'type' => 'A', 'name' => '@', 'data' => '138.68.45.154'],
                ['id' => 11, 'type' => 'MX', 'name' => '@', 'data' => 'mx01.mail.icloud.com'],
            ]], 200);
        }

        return Http::response(['domain_record' => ['id' => 9, 'type' => 'A', 'name' => '@', 'data' => '138.68.45.154']], 200);
    });

    $record = (new DigitalOceanService('dop_v1_test'))
        ->upsertDomainRecord('tjshafer.com', 'A', '@', '138.68.45.154');

    // The record that already holds the wanted value is kept, so a no-op
    // re-apply neither rewrites nor renumbers anything...
    expect($record['id'])->toBe(9);
    // ...the stale one goes...
    expect($deleted)->toBe(['7']);
    // ...and records of other types at the same name are untouched.
    expect($deleted)->not->toContain('11');
});

test('upsert creates the record when the name has none', function () {
    $posted = false;

    Http::fake(function ($request) use (&$posted) {
        if ($request->method() === 'GET') {
            return Http::response(['domain_records' => []], 200);
        }

        $posted = true;

        return Http::response(['domain_record' => ['id' => 3, 'type' => 'A', 'name' => 'app', 'data' => '203.0.113.10']], 200);
    });

    $record = (new DigitalOceanService('dop_v1_test'))
        ->upsertDomainRecord('example.com', 'A', 'app', '203.0.113.10');

    expect($posted)->toBeTrue();
    expect($record['id'])->toBe(3);
});
