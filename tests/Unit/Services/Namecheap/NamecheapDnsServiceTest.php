<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Namecheap\NamecheapDnsServiceTest;

use App\Modules\Providers\Namecheap\NamecheapDnsService;
use Illuminate\Support\Facades\Http;

function namecheapXml(string $hosts): string
{
    return <<<XML
<?xml version="1.0"?>
<ApiResponse Status="OK" xmlns="http://api.namecheap.com/xml.response">
  <CommandResponse Type="namecheap.domains.dns.getHosts">
    <DomainDNSGetHostsResult Domain="on-dply.cc" EmailType="FWD">
      {$hosts}
    </DomainDNSGetHostsResult>
  </CommandResponse>
</ApiResponse>
XML;
}

test('upserts an A record through namecheap setHosts', function () {
    Http::fake([
        'api.namecheap.com/*' => Http::sequence()
            ->push(namecheapXml('<host HostId="1" Name="@" Type="A" Address="1.1.1.1" MXPref="10" TTL="1799"/>'), 200)
            ->push(namecheapXml(''), 200),
    ]);

    $service = new NamecheapDnsService('user', 'key', 'user', '1.2.3.4');
    $record = $service->upsertARecord('on-dply.cc', 'demo-site', '203.0.113.10');

    expect($record['id'])->toBe('demo-site/A');
    expect($record['value'])->toBe('203.0.113.10');

    Http::assertSent(function ($request): bool {
        return str_contains((string) $request->url(), 'Command=namecheap.domains.dns.setHosts')
            && str_contains((string) $request->url(), 'HostName2=demo-site')
            && str_contains((string) $request->url(), 'Address2=203.0.113.10');
    });
});

test('is configured only when user key and client ip are set', function () {
    config([
        'services.namecheap.api_user' => 'user',
        'services.namecheap.api_key' => 'key',
        'services.namecheap.client_ip' => '1.2.3.4',
    ]);

    expect(NamecheapDnsService::isConfigured())->toBeTrue();

    config(['services.namecheap.api_key' => '']);

    expect(NamecheapDnsService::isConfigured())->toBeFalse();
});
