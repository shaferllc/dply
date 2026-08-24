<?php

declare(strict_types=1);

use App\Models\Server;
use App\Models\ServerWildcardCertificate;
use App\Models\Site;
use App\Modules\Certificates\Jobs\IssueServerWildcardCertificateJob;
use App\Services\Sites\SiteProvisioner;
use App\Services\Sites\SiteWebserverConfigApplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function wildcardTlsSite(): Site
{
    config([
        'sites.wildcard_testing_ssl' => true,
        'services.cloudflare.key' => 'cf-test-token',
        'edge.cloudflare.api_token' => '',
    ]);

    $server = Server::factory()->create([
        'status' => Server::STATUS_READY,
        'ip_address' => '203.0.113.10',
        'meta' => ['webserver' => 'nginx'],
    ]);

    return Site::factory()->create([
        'server_id' => $server->id,
        'document_root' => '/var/www/demo/public',
        'meta' => [
            'testing_hostname' => [
                'status' => 'ready',
                'hostname' => 'demo-aaaaaaaa.on-dply.cc',
                'zone' => 'on-dply.cc',
            ],
        ],
    ]);
}

test('stale issuing wildcard is re-dispatched on the next provision probe', function (): void {
    Queue::fake();

    $site = wildcardTlsSite();

    ServerWildcardCertificate::query()->create([
        'server_id' => $site->server_id,
        'zone' => 'on-dply.cc',
        'provider' => 'cloudflare',
        'status' => ServerWildcardCertificate::STATUS_ISSUING,
        'live_directory' => 'on-dply.cc',
        'last_requested_at' => now()->subMinutes(15),
    ]);

    $applier = Mockery::mock(SiteWebserverConfigApplier::class);
    $applier->shouldNotReceive('apply');
    app()->instance(SiteWebserverConfigApplier::class, $applier);

    $ok = app(SiteProvisioner::class)->ensureWebserverConfigForReachability($site->fresh(['server']));

    expect($ok)->toBeFalse()
        ->and($site->fresh()->provisioningState())->toBe('waiting_for_wildcard_tls');

    Queue::assertPushed(IssueServerWildcardCertificateJob::class, function (IssueServerWildcardCertificateJob $job) use ($site): bool {
        return $job->serverId === (string) $site->server_id && $job->zone === 'on-dply.cc';
    });
});

test('in-flight issuing wildcard is not re-dispatched on every probe', function (): void {
    Queue::fake();

    $site = wildcardTlsSite();

    ServerWildcardCertificate::query()->create([
        'server_id' => $site->server_id,
        'zone' => 'on-dply.cc',
        'provider' => 'cloudflare',
        'status' => ServerWildcardCertificate::STATUS_ISSUING,
        'live_directory' => 'on-dply.cc',
        'last_requested_at' => now()->subMinutes(2),
    ]);

    $ok = app(SiteProvisioner::class)->ensureWebserverConfigForReachability($site->fresh(['server']));

    expect($ok)->toBeFalse();
    Queue::assertNotPushed(IssueServerWildcardCertificateJob::class);
});
