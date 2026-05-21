<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\DigitalOceanService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DigitalOceanServiceDnsTest extends TestCase
{
    public function test_it_fetches_domain_when_present_in_account(): void
    {
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

        $this->assertNotNull($payload);
        $this->assertSame('example.com', $payload['name']);
        $this->assertTrue($service->domainExistsInAccount('example.com'));
    }

    public function test_it_returns_null_when_domain_missing_in_account(): void
    {
        Http::fake([
            'https://api.digitalocean.com/v2/domains/unknown.test' => Http::response(['message' => 'Not found'], 404),
        ]);

        $service = new DigitalOceanService('dop_v1_test');
        $this->assertNull($service->fetchDomain('unknown.test'));
        $this->assertFalse($service->domainExistsInAccount('unknown.test'));
    }

    public function test_it_can_find_a_matching_domain_record(): void
    {
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

        $this->assertNotNull($record);
        $this->assertSame(42, $record['id']);
    }

    public function test_it_falls_back_to_local_filtering_when_api_name_filter_misses_existing_record(): void
    {
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

        $this->assertNotNull($record);
        $this->assertSame(77, $record['id']);
    }

    public function test_it_follows_pagination_when_listing_domain_records(): void
    {
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

        $this->assertCount(2, $records);
        $this->assertSame([1, 2], array_map(static fn ($r) => $r['id'], $records));
    }

    public function test_it_can_create_a_domain_record(): void
    {
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

        $this->assertSame(73, $record['id']);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.digitalocean.com/v2/domains/dply.cc/records'
                && $request['type'] === 'A'
                && $request['name'] === 'preview-app'
                && $request['data'] === '203.0.113.10';
        });
    }

    public function test_it_can_delete_a_domain_record(): void
    {
        Http::fake([
            'https://api.digitalocean.com/v2/domains/dply.cc/records/73' => Http::response([], 204),
        ]);

        (new DigitalOceanService('dop_v1_test'))
            ->deleteDomainRecord('dply.cc', 73);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.digitalocean.com/v2/domains/dply.cc/records/73');
    }
}
