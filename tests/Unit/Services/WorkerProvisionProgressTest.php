<?php

declare(strict_types=1);

use App\Enums\ServerProvider;
use App\Models\Server;
use App\Models\WorkerPool;
use App\Services\WorkerPools\WorkerProvisionProgress;

function workerProgressServer(array $overrides = []): Server
{
    return new Server(array_merge([
        'name' => 'dply-app-2',
        'provider' => ServerProvider::DigitalOcean,
        'status' => Server::STATUS_PENDING,
        'setup_status' => Server::SETUP_STATUS_PENDING,
        'ip_address' => null,
        'region' => 'sfo2',
        'size' => 's-2vcpu-8gb-160gb-intel',
        'meta' => ['pool' => ['state' => WorkerPool::MEMBER_PROVISIONING]],
    ], $overrides));
}

it('reports queued before the provider starts the VM', function () {
    $progress = app(WorkerProvisionProgress::class)->for(workerProgressServer());

    expect($progress['label'])->toBe('Queued with DigitalOcean')
        ->and($progress['step'])->toBe(1)
        ->and($progress['of'])->toBe(7)
        ->and($progress['detail'])->toContain('start creating');
});

it('reports creating the VM before an IP is assigned', function () {
    $progress = app(WorkerProvisionProgress::class)->for(workerProgressServer([
        'status' => Server::STATUS_PROVISIONING,
        'ip_address' => null,
    ]));

    expect($progress['label'])->toBe('Creating the VM')
        ->and($progress['step'])->toBe(2);
});

it('reports waiting for SSH once the VM has an IP', function () {
    $progress = app(WorkerProvisionProgress::class)->for(workerProgressServer([
        'status' => Server::STATUS_PROVISIONING,
        'ip_address' => '138.68.248.94',
    ]));

    expect($progress['label'])->toBe('Waiting for SSH')
        ->and($progress['step'])->toBe(4)
        ->and($progress['detail'])->toContain('138.68.248.94');
});

it('reports site deploy after the box is ready', function () {
    $progress = app(WorkerProvisionProgress::class)->for(workerProgressServer([
        'status' => Server::STATUS_READY,
        'setup_status' => Server::SETUP_STATUS_DONE,
        'ip_address' => '138.68.248.94',
        'meta' => ['pool' => ['state' => WorkerPool::MEMBER_DEPLOYING]],
    ]));

    expect($progress['label'])->toBe('Deploying this site’s release')
        ->and($progress['step'])->toBe(7);
});

it('hides progress on a healthy idle worker', function () {
    expect(app(WorkerProvisionProgress::class)->for(workerProgressServer([
        'status' => Server::STATUS_READY,
        'setup_status' => Server::SETUP_STATUS_DONE,
        'meta' => ['pool' => ['state' => WorkerPool::MEMBER_ACTIVE]],
    ])))->toBeNull();
});

it('hides progress on a failed worker so the error row owns the copy', function () {
    expect(app(WorkerProvisionProgress::class)->for(workerProgressServer([
        'status' => Server::STATUS_ERROR,
        'meta' => ['pool' => ['state' => WorkerPool::MEMBER_ERRORED]],
    ])))->toBeNull();
});
