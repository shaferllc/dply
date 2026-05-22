<?php

namespace Tests\Feature\BackupsTest;

use App\Jobs\ExportSiteFileBackupJob;
use App\Livewire\Backups\Databases;
use App\Livewire\Backups\Files;
use App\Models\BackupConfiguration;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\ServerDatabaseBackup;
use App\Models\Site;
use App\Models\SiteFileBackup;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

const FAKE_SSH_KEY = "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n";

function userWithOrganization(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}

test('guest cannot view backups', function () {
    $this->get('/backups/databases')->assertRedirect();
    $this->get('/backups/files')->assertRedirect();
});

test('backups redirects to databases', function () {
    $user = userWithOrganization();

    $this->actingAs($user)
        ->get('/backups')
        ->assertRedirect('/backups/databases');
});

test('authenticated user can view database backups page', function () {
    $user = userWithOrganization();

    $this->actingAs($user)
        ->get(route('backups.databases'))
        ->assertOk()
        ->assertSee('Database backups', false)
        ->assertSee(route('launches.create'), false);
});

test('authenticated user can view file backups page', function () {
    $user = userWithOrganization();

    $this->actingAs($user)
        ->get(route('backups.files'))
        ->assertOk()
        ->assertSee('File backups', false)
        ->assertSee(route('launches.create'), false);
});

test('backups livewire components render', function () {
    $user = userWithOrganization();

    Livewire::actingAs($user)
        ->test(Databases::class)
        ->assertOk();

    Livewire::actingAs($user)
        ->test(Files::class)
        ->assertOk();
});

test('database backups page shows storage destinations and latest exports', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();

    BackupConfiguration::query()->create([
        'organization_id' => $org->id,
        'created_by_user_id' => $user->id,
        'name' => 'Primary S3',
        'provider' => BackupConfiguration::PROVIDER_CUSTOM_S3,
        'config' => [
            'access_key' => 'abc',
            'secret' => 'def',
            'bucket' => 'backups',
        ],
    ]);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'Marketing',
    ]);

    $database = ServerDatabase::query()->create([
        'server_id' => $server->id,
        'name' => 'app_db',
        'engine' => 'mysql',
        'username' => 'app',
        'password' => 'secret',
        'host' => '127.0.0.1',
    ]);

    ServerDatabaseBackup::query()->create([
        'server_database_id' => $database->id,
        'user_id' => $user->id,
        'status' => ServerDatabaseBackup::STATUS_COMPLETED,
        'disk_path' => 'backups/app_db.sql',
        'bytes' => 12345,
    ]);

    $this->actingAs($user)
        ->get(route('backups.databases'))
        ->assertOk()
        ->assertSee('Primary S3', false)
        ->assertSee('Latest export: Completed', false)
        ->assertSee('app_db', false)
        ->assertSee('1 tracked database on this server.', false);
});

test('file backups page shows storage destinations and runbook readiness', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();

    BackupConfiguration::query()->create([
        'organization_id' => $org->id,
        'created_by_user_id' => $user->id,
        'name' => 'Archive Bucket',
        'provider' => BackupConfiguration::PROVIDER_AWS_S3,
        'config' => [
            'access_key' => 'abc',
            'secret' => 'def',
            'bucket' => 'archives',
        ],
    ]);

    $workspace = Workspace::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'name' => 'Customer Stack',
    ]);

    $workspace->runbooks()->create([
        'title' => 'Restore uploads',
        'body' => 'Restore uploads from object storage and clear caches.',
        'sort_order' => 1,
    ]);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'workspace_id' => $workspace->id,
        'ssh_private_key' => FAKE_SSH_KEY,
    ]);

    Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'workspace_id' => $workspace->id,
        'name' => 'Docs',
        'document_root' => '/var/www/docs/current/public',
        'repository_path' => '/var/www/docs',
    ]);

    $this->actingAs($user)
        ->get(route('backups.files'))
        ->assertOk()
        ->assertSee('Archive Bucket', false)
        ->assertSee('Document root: /var/www/docs/current/public', false)
        ->assertSee('1 project runbook is already attached to this site workspace.', false)
        ->assertSee('Queue full backup', false);
});

test('queue full file backup dispatches export job', function () {
    Queue::fake();

    $user = userWithOrganization();
    $org = $user->currentOrganization();

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'ssh_private_key' => FAKE_SSH_KEY,
    ]);

    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'App',
        'repository_path' => '/var/www/app',
    ]);

    Livewire::actingAs($user)
        ->test(Files::class)
        ->call('queueFullBackup', $site->id)
        ->assertHasNoErrors();

    Queue::assertPushed(ExportSiteFileBackupJob::class);

    $this->assertDatabaseHas('site_file_backups', [
        'site_id' => $site->id,
        'user_id' => $user->id,
        'status' => SiteFileBackup::STATUS_PENDING,
    ]);
});
