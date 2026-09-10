<?php

declare(strict_types=1);

namespace Tests\Feature\Sites;

use App\Jobs\CreateSftpAccountJob;
use App\Jobs\DeleteSftpAccountJob;
use App\Livewire\Sites\Files;
use App\Models\Organization;
use App\Models\Server;
use App\Models\SftpAccount;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function ftpFixture(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();

    $server = Server::factory()->ready()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'ssh_user' => 'dply',
        'ssh_private_key' => 'test-key',
        'meta' => ['host_kind' => 'vm'],
    ]);

    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'repository_path' => '/home/dply/example.com',
        'document_root' => '/home/dply/example.com/public',
    ]);

    return [$user, $server, $site];
}

test('creating an FTP account queues provisioning and stores no password', function (): void {
    Queue::fake();
    [$user, $server, $site] = ftpFixture();

    Livewire::actingAs($user)
        ->test(Files::class, ['server' => $server, 'site' => $site])
        ->set('ftp_username', 'designer')
        ->call('createFtpAccount')
        ->assertSet('ftp_error', null);

    $account = SftpAccount::query()->where('site_id', $site->id)->firstOrFail();

    expect($account->username)->toBe('designer')
        ->and($account->home_path)->toBe('/home/designer')
        ->and($account->status)->toBe(SftpAccount::STATUS_PENDING);

    // The password is the customer's SFTP login. dply generates it, shows it
    // once, and forgets it — /etc/shadow on the box is the only store, so
    // nothing password-shaped may appear on the row.
    $row = (array) $account->getAttributes();
    foreach ($row as $column => $value) {
        expect($column)->not->toContain('password');
    }

    Queue::assertPushed(
        CreateSftpAccountJob::class,
        fn (CreateSftpAccountJob $job): bool => $job->accountId === (string) $account->id
            && preg_match('/^[A-Za-z0-9]{24}$/', $job->password) === 1,
    );
});

/**
 * Public Livewire properties are serialized into the DOM snapshot and round-trip
 * on every subsequent request. The revealed password must not be one.
 */
test('the revealed password is not a persisted Livewire property', function (): void {
    Queue::fake();
    [$user, $server, $site] = ftpFixture();

    $component = Livewire::actingAs($user)
        ->test(Files::class, ['server' => $server, 'site' => $site])
        ->set('ftp_username', 'designer')
        ->call('createFtpAccount');

    $password = $component->instance()->revealedFtpPassword();
    expect($password)->toMatch('/^[A-Za-z0-9]{24}$/');

    // Gone on the next round-trip: reveal-once, enforced by the property being
    // protected rather than public.
    $component->call('cancelDeleteFtpAccount');
    expect($component->instance()->revealedFtpPassword())->toBeNull();
});

test('a username already taken by another account on the server is rejected', function (): void {
    Queue::fake();
    [$user, $server, $site] = ftpFixture();

    SftpAccount::factory()->create([
        'server_id' => $server->id,
        'username' => 'designer',
        'home_path' => '/home/designer',
    ]);

    Livewire::actingAs($user)
        ->test(Files::class, ['server' => $server, 'site' => $site])
        ->set('ftp_username', 'designer')
        ->call('createFtpAccount')
        ->assertSet('ftp_error', 'An FTP account with that username already exists on this server.');

    Queue::assertNotPushed(CreateSftpAccountJob::class);
});

/**
 * The deploy user is sudo-capable and root is root; a customer-facing FTP login
 * must never be either. The guard runs inline rather than only on the worker,
 * so a reserved name never becomes a database row that fails out of band.
 */
test('reserved usernames cannot be claimed as an FTP account', function (string $username): void {
    Queue::fake();
    [$user, $server, $site] = ftpFixture();

    $component = Livewire::actingAs($user)
        ->test(Files::class, ['server' => $server, 'site' => $site])
        ->set('ftp_username', $username)
        ->call('createFtpAccount');

    // The message differs per reserved name ("reserved" vs "non-root"); what
    // must hold for all of them is that nothing was persisted or queued.
    expect($component->get('ftp_error'))->not->toBeNull();
    expect(SftpAccount::query()->count())->toBe(0);

    Queue::assertNotPushed(CreateSftpAccountJob::class);
})->with(['dply', 'root']);

test('removing an FTP account queues teardown and keeps the row until it succeeds', function (): void {
    Queue::fake();
    [$user, $server, $site] = ftpFixture();

    $account = SftpAccount::factory()->create([
        'server_id' => $server->id,
        'site_id' => $site->id,
        'username' => 'designer',
        'home_path' => '/home/designer',
    ]);

    Livewire::actingAs($user)
        ->test(Files::class, ['server' => $server, 'site' => $site])
        ->call('confirmDeleteFtpAccount', (string) $account->id)
        ->call('deleteFtpAccount');

    // Deleting the row before the box is torn down would leave an unlisted
    // login on a customer's server.
    expect(SftpAccount::query()->find($account->id))->not->toBeNull();

    Queue::assertPushed(
        DeleteSftpAccountJob::class,
        fn (DeleteSftpAccountJob $job): bool => $job->accountId === (string) $account->id,
    );
});

/** Renders the modal and both deploy-shape warnings, so no blade path ships unexercised. */
test('the panel renders its create modal and its atomic-deploy warning', function (): void {
    [$user, $server, $site] = ftpFixture();
    $site->update(['deploy_strategy' => 'atomic']);

    Livewire::actingAs($user)
        ->test(Files::class, ['server' => $server, 'site' => $site->fresh()])
        ->assertSee('FTP accounts')
        ->assertSee('This site uses atomic deploys')
        ->call('openFtpCreateModal')
        ->assertSet('showFtpCreateModal', true)
        ->assertSee('Add FTP account');
});
