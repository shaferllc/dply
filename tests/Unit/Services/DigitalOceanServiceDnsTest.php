<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\DigitalOceanService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DigitalOceanServiceDnsTest extends TestCase
{
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
        Http::fake([
            'https://api.digitalocean.com/v2/domains/dply.cc/records?type=A&name=preview-app' => Http::response([
                'domain_records' => [],
            ], 200),
            'https://api.digitalocean.com/v2/domains/dply.cc/records' => Http::response([
                'domain_records' => [
                    [
                        'id' => 77,
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
        $this->assertSame(77, $record['id']);
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
