<?php

declare(strict_types=1);

namespace Tests\Feature\ProvisionSiteSystemdUnitsJobTest;

use App\Contracts\RemoteShell;
use App\Jobs\ProvisionSiteSystemdUnitsJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteProcess;
use App\Services\Sites\SiteSystemdProvisioner;
use App\Services\Sites\SiteSystemdUnitBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

uses(RefreshDatabase::class);

test('job provisions units for node site', function () {
    $server = Server::factory()->ready()->create([
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'runtime' => 'node',
        'start_command' => 'npm start',
        'internal_port' => 30001,
    ]);

    $shell = new ProvisionRecordingShell;
    $provisioner = new class($shell) extends SiteSystemdProvisioner
    {
        public function __construct(private RemoteShell $shell)
        {
            parent::__construct(new SiteSystemdUnitBuilder);
        }

        public function provision(Site $site, ?\Closure $shellFactory = null): array
        {
            return parent::provision($site, fn () => $this->shell);
        }
    };
    $this->app->instance(SiteSystemdProvisioner::class, $provisioner);

    (new ProvisionSiteSystemdUnitsJob($site->id))->handle($provisioner);

    // Web unit was uploaded; daemon-reload was called.
    expect($shell->putFiles)->not->toBeEmpty();
    expect(collect($shell->execCalls)->contains(
        fn ($c) => $c === 'sudo systemctl daemon-reload'
    ))->toBeTrue();
});
test('job skips php runtime', function () {
    $server = Server::factory()->ready()->create([
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'runtime' => 'php',
    ]);

    $provisioner = Mockery::mock(SiteSystemdProvisioner::class);
    $provisioner->shouldNotReceive('provision');

    (new ProvisionSiteSystemdUnitsJob($site->id))->handle($provisioner);
});
test('job skips when site has no start command', function () {
    $server = Server::factory()->ready()->create([
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'runtime' => 'node',
        'start_command' => null,
    ]);

    $provisioner = Mockery::mock(SiteSystemdProvisioner::class);
    $provisioner->shouldNotReceive('provision');

    (new ProvisionSiteSystemdUnitsJob($site->id))->handle($provisioner);
});
test('job silently returns when site does not exist', function () {
    $provisioner = Mockery::mock(SiteSystemdProvisioner::class);
    $provisioner->shouldNotReceive('provision');

    (new ProvisionSiteSystemdUnitsJob('01HMISSINGIDDOESNOTEXIST00'))
        ->handle($provisioner);

    // Returning normally is the assertion.
    expect(true)->toBeTrue();
});
test('job provisions units for each non web process', function () {
    $server = Server::factory()->ready()->create([
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'runtime' => 'python',
        'start_command' => 'gunicorn app:app',
        'internal_port' => 30002,
    ]);
    $site->processes()->create([
        'type' => SiteProcess::TYPE_WORKER,
        'name' => 'celery',
        'command' => 'celery -A app worker',
    ]);

    $shell = new ProvisionRecordingShell;
    $provisioner = new class($shell) extends SiteSystemdProvisioner
    {
        public function __construct(private RemoteShell $shell)
        {
            parent::__construct(new SiteSystemdUnitBuilder);
        }

        public function provision(Site $site, ?\Closure $shellFactory = null): array
        {
            return parent::provision($site, fn () => $this->shell);
        }
    };

    (new ProvisionSiteSystemdUnitsJob($site->id))->handle($provisioner);

    // Two units uploaded (web + celery).
    expect($shell->putFiles)->toHaveCount(2);
    $contents = collect($shell->putFiles)->pluck('contents');
    expect($contents->contains(fn ($c) => str_contains($c, 'gunicorn app:app')))->toBeTrue();
    expect($contents->contains(fn ($c) => str_contains($c, 'celery -A app worker')))->toBeTrue();
});
