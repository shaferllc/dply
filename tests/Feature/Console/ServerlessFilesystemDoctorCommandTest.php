<?php

declare(strict_types=1);

namespace Tests\Feature\Console\ServerlessFilesystemDoctorCommandTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function filesystemDoctorSite(string $env): Site
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
        'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS],
    ]);

    return Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Site::STATUS_FUNCTIONS_ACTIVE,
        'env_file_content' => $env,
        'meta' => [
            'runtime_profile' => 'digitalocean_functions_web',
            'serverless' => ['action_url' => 'https://faas.example/api/v1/web/fn/default/demo'],
        ],
    ]);
}

test('it flags local disk and file sessions', function () {
    $site = filesystemDoctorSite("FILESYSTEM_DISK=local\nSESSION_DRIVER=file\nQUEUE_CONNECTION=sync\n");

    $this->artisan('dply:serverless:filesystem-doctor', ['site' => $site->id])
        ->expectsOutputToContain('FILESYSTEM_DISK')
        ->expectsOutputToContain('SESSION_DRIVER')
        ->assertFailed();
});

test('fix rewrites file sessions to cookie', function () {
    $site = filesystemDoctorSite("FILESYSTEM_DISK=s3\nSESSION_DRIVER=file\nQUEUE_CONNECTION=redis\n");

    $this->artisan('dply:serverless:filesystem-doctor', ['site' => $site->id, '--fix' => true])
        ->expectsOutputToContain('SESSION_DRIVER=cookie')
        ->assertSuccessful();

    expect((string) $site->fresh()->env_file_content)->toContain('SESSION_DRIVER=cookie');
    expect((string) $site->fresh()->env_file_content)->not->toContain('SESSION_DRIVER=file');
});

test('a cookie session with object storage is healthy', function () {
    $site = filesystemDoctorSite("FILESYSTEM_DISK=s3\nSESSION_DRIVER=cookie\nQUEUE_CONNECTION=redis\n");

    $this->artisan('dply:serverless:filesystem-doctor', ['site' => $site->id])
        ->assertSuccessful();
});
