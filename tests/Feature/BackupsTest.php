<?php

namespace Tests\Feature\BackupsTest;

use App\Livewire\Backups\Databases;
use App\Livewire\Backups\Files;
use App\Models\BackupConfiguration;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\ServerDatabaseBackup;
use App\Models\Site;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\Backups\Jobs\ExportSiteFileBackupJob;
use App\Modules\Backups\Models\SiteFileBackup;
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
    $this->get('/backups')->assertRedirect();
    $this->get('/backups/databases')->assertRedirect();
    $this->get('/backups/snapshots')->assertRedirect();
    $this->get('/backups/storage')->assertRedirect();
    $this->get('/backups/files')->assertRedirect();
});

test('every backups tab renders', function () {
    $user = userWithOrganization();

    // The five-tab product surface (docs/adr/backups-as-a-product.md, decision 1).
    // A tab that 500s is invisible until someone clicks it, so each one is a route
    // assertion rather than a manual check.
    foreach ([
        'backups.overview' => 'Backups',
        'backups.databases' => 'Databases',
        'backups.files' => 'File backups',
        'backups.snapshots' => 'Snapshots',
        'backups.storage' => 'Backup destinations',
    ] as $route => $heading) {
        $this->actingAs($user)
            ->get(route($route))
            ->assertOk()
            ->assertSee($heading, false);
    }
});

test('the old destinations URL redirects into the Storage tab', function () {
    $user = userWithOrganization();

    $this->actingAs($user)
        ->get('/profile/backup-configurations')
        ->assertRedirect('/backups/storage');
});

test('authenticated user can view the backups overview', function () {
    $user = userWithOrganization();

    $this->actingAs($user)
        ->get(route('backups.overview'))
        ->assertOk()
        ->assertSee('Backups', false)
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

test('the databases tab shows its dumps and the storage tab its destinations', function () {
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

    // Each tab owns its own type end-to-end: the run history and the target live
    // on Databases, the destinations they ship to live on Storage. The Overview
    // deliberately repeats neither table.
    $this->actingAs($user)
        ->get(route('backups.databases'))
        ->assertOk()
        ->assertSee('Done', false)
        ->assertSee('app_db', false);

    $this->actingAs($user)
        ->get(route('backups.storage'))
        ->assertOk()
        ->assertSee('Primary S3', false);
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
        // The Files tab now folds each site's schedule, last archive and actions
        // into one row, so it shows the archive root rather than a labelled
        // "Document root:" line, and the action reads "Archive".
        ->assertSee('Docs', false)
        ->assertSee('/var/www/docs', false)
        ->assertSee('1 runbook', false)
        ->assertSee('Archive', false);
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
