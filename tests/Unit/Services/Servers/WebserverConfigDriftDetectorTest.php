<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Servers\WebserverConfigDriftDetectorTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Servers\WebserverConfigDriftDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;

uses(RefreshDatabase::class);

test('nginx drift path uses sites-enabled conf suffix like provisioner', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['webserver' => 'nginx'],
    ]);

    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'slug' => 'demo',
    ]);

    $method = new ReflectionMethod(WebserverConfigDriftDetector::class, 'pathFor');
    $path = $method->invoke(app(WebserverConfigDriftDetector::class), 'nginx', $site);

    expect($path)->toBe('/etc/nginx/sites-enabled/dply-'.$site->id.'-demo.conf');
});
