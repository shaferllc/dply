<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Certificates;

use App\Modules\Certificates\Jobs\ExecuteSiteCertificateJob;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteCertificate;
use App\Models\User;
use App\Modules\Certificates\Services\CertificateRepairService;
use App\Services\Sites\SiteWebserverConfigApplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('certificate repair re-applies webserver config and requeues failed cert', function (): void {
    Queue::fake();

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'status' => Server::STATUS_READY,
        'setup_status' => Server::SETUP_STATUS_DONE,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'ssl_status' => Site::SSL_FAILED,
    ]);

    $certificate = SiteCertificate::query()->create([
        'site_id' => $site->id,
        'scope_type' => SiteCertificate::SCOPE_PREVIEW,
        'provider_type' => SiteCertificate::PROVIDER_LETSENCRYPT,
        'challenge_type' => SiteCertificate::CHALLENGE_HTTP,
        'domains_json' => ['testing.example.test'],
        'status' => SiteCertificate::STATUS_FAILED,
        'last_output' => 'certbot failed',
    ]);

    $applier = \Mockery::mock(SiteWebserverConfigApplier::class);
    $applier->shouldReceive('apply')->once()->with(\Mockery::on(fn (Site $passed): bool => $passed->id === $site->id));
    app()->instance(SiteWebserverConfigApplier::class, $applier);

    app(CertificateRepairService::class)->repair($site, $certificate, (string) $user->id);

    expect($certificate->fresh()->status)->toBe(SiteCertificate::STATUS_PENDING)
        ->and($certificate->fresh()->last_output)->toBeNull()
        ->and($site->fresh()->ssl_status)->toBe(Site::SSL_PENDING);

    Queue::assertPushed(ExecuteSiteCertificateJob::class, fn (ExecuteSiteCertificateJob $job): bool => $job->certificateId === $certificate->id
        && $job->userId === (string) $user->id);
});
