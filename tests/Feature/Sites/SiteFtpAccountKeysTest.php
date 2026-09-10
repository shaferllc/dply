<?php

declare(strict_types=1);

namespace Tests\Feature\Sites;

use App\Jobs\DeleteSftpAccountJob;
use App\Jobs\SyncAuthorizedKeysJob;
use App\Livewire\Sites\Files;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerAuthorizedKey;
use App\Models\SftpAccount;
use App\Models\Site;
use App\Models\User;
use App\Services\Servers\SftpAccountProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;

uses(RefreshDatabase::class);

afterEach(function () {
    Mockery::close();
});

const FTP_TEST_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIJp8Q0Nc0nQzE8sQZ2v0K5m1oJqXk9RtLb7YwFhVn3Cd designer@laptop';

function ftpKeyFixture(): array
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

    $account = SftpAccount::factory()->create([
        'server_id' => $server->id,
        'site_id' => $site->id,
        'username' => 'designer',
        'home_path' => '/home/designer',
    ]);

    return [$user, $server, $site, $account];
}

/**
 * Keys are stored as ServerAuthorizedKey rows targeted at the account's Linux
 * user, so FTP accounts inherit the synchronizer's fingerprint reconcile — which
 * is what keeps an adopted account's pre-existing keys from being wiped.
 */
test('adding a key targets the account linux user and queues a sync', function (): void {
    Queue::fake();
    [$user, $server, $site, $account] = ftpKeyFixture();

    Livewire::actingAs($user)
        ->test(Files::class, ['server' => $server, 'site' => $site])
        ->call('openFtpKeyModal', (string) $account->id)
        ->set('ftp_key_name', 'Designer laptop')
        ->set('ftp_key_public', FTP_TEST_KEY)
        ->call('addFtpKey')
        ->assertSet('ftp_error', null)
        ->assertSet('ftp_key_account_id', null);

    $key = ServerAuthorizedKey::query()->firstOrFail();

    expect($key->target_linux_user)->toBe('designer')
        ->and($key->name)->toBe('Designer laptop')
        ->and($key->public_key)->toBe(FTP_TEST_KEY);

    Queue::assertPushed(SyncAuthorizedKeysJob::class);
});

test('a malformed public key is rejected before anything is written', function (): void {
    Queue::fake();
    [$user, $server, $site, $account] = ftpKeyFixture();

    $component = Livewire::actingAs($user)
        ->test(Files::class, ['server' => $server, 'site' => $site])
        ->call('openFtpKeyModal', (string) $account->id)
        ->set('ftp_key_public', 'this is not a key')
        ->call('addFtpKey');

    expect($component->get('ftp_error'))->not->toBeNull();
    expect(ServerAuthorizedKey::query()->count())->toBe(0);

    Queue::assertNotPushed(SyncAuthorizedKeysJob::class);
});

/**
 * The site surface must never reach a key belonging to the deploy user or a
 * shell account — removal is scoped to this site's own FTP accounts.
 */
test('removing a key cannot touch a key outside this site FTP accounts', function (): void {
    Queue::fake();
    [$user, $server, $site, $account] = ftpKeyFixture();

    $foreign = ServerAuthorizedKey::query()->create([
        'server_id' => $server->id,
        'target_linux_user' => 'dply',
        'name' => 'Deploy user key',
        'public_key' => FTP_TEST_KEY,
    ]);

    Livewire::actingAs($user)
        ->test(Files::class, ['server' => $server, 'site' => $site])
        ->call('removeFtpKey', (string) $foreign->id);

    expect(ServerAuthorizedKey::query()->find($foreign->id))->not->toBeNull();
    Queue::assertNotPushed(SyncAuthorizedKeysJob::class);
});

/**
 * Keys are per-Linux-user, not per-FTP-account, so they outlive the account
 * unless teardown removes them — otherwise they would be re-synced onto a user
 * that no longer exists, or keep granting access to an adopted one.
 */
test('tearing down an account removes its keys', function (): void {
    Queue::fake();
    [$user, $server, $site, $account] = ftpKeyFixture();

    ServerAuthorizedKey::query()->create([
        'server_id' => $server->id,
        'target_linux_user' => 'designer',
        'name' => 'Designer laptop',
        'public_key' => FTP_TEST_KEY,
    ]);

    $provisioner = Mockery::mock(SftpAccountProvisioner::class);
    $provisioner->shouldReceive('destroy')->once();

    (new DeleteSftpAccountJob((string) $account->id, (string) $user->id))->handle($provisioner);

    expect(ServerAuthorizedKey::query()->where('target_linux_user', 'designer')->count())->toBe(0);
    Queue::assertPushed(SyncAuthorizedKeysJob::class);
});

/**
 * `@` immediately before `{{` is Blade's escape directive, so writing the URI
 * as `{{ $user }}@{{ $host }}` rendered the literal braces to the operator
 * instead of the hostname. The URI is built in PHP now; this asserts the real
 * host reaches the page.
 */
test('the connection string renders a real host, not escaped blade braces', function (): void {
    [$user, $server, $site, $account] = ftpKeyFixture();
    $server->update(['ip_address' => '203.0.113.10']);

    $html = Livewire::actingAs($user)
        ->test(Files::class, ['server' => $server->fresh(), 'site' => $site])
        ->html();

    expect($html)->toContain('sftp://designer@203.0.113.10:22')
        ->and($html)->not->toContain('ftpHost }}');
});
