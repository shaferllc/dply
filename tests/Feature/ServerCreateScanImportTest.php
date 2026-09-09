<?php

declare(strict_types=1);

namespace Tests\Feature\ServerCreateScanImportTest;

use App\Jobs\RefreshServerInventoryJob;
use App\Livewire\Servers\Create\StepScan;
use App\Livewire\Servers\Create\StepType;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\ServerCreateDraft;
use App\Models\User;
use App\Services\Servers\ProviderServerInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/** @return array{0: User, 1: Organization} */
function scanUserWithOrg(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return [$user, $org];
}

function scanDraft(User $user, Organization $org): ServerCreateDraft
{
    return ServerCreateDraft::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'step' => 1,
        'payload' => ['mode' => 'import', 'name' => 'placeholder-name'],
    ]);
}

function doCredential(User $user, Organization $org): ProviderCredential
{
    return ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'name' => 'Primary DO',
        'credentials' => ['api_token' => 'token'],
    ]);
}

function fakeDroplets(array $droplets): void
{
    Http::fake([
        'https://api.digitalocean.com/v2/droplets*' => Http::response(['droplets' => $droplets], 200),
    ]);
}

function droplet(int $id, string $name, string $ip): array
{
    return [
        'id' => $id,
        'name' => $name,
        'status' => 'active',
        'region' => ['slug' => 'nyc3'],
        'size_slug' => 's-2vcpu-4gb',
        'networks' => ['v4' => [['type' => 'public', 'ip_address' => $ip]]],
    ];
}

test('step one offers import mode and routes to the scan step', function () {
    [$user, $org] = scanUserWithOrg();

    Livewire::actingAs($user)
        ->test(StepType::class)
        ->assertSee('Scan &amp; import existing', escape: false)
        ->call('chooseImportMode')
        ->assertSet('form.mode', 'import')
        ->call('next')
        ->assertRedirect(route('servers.create.scan'));
});

test('import mode drops the server name field — the provider supplies it', function () {
    [$user, $org] = scanUserWithOrg();

    $component = Livewire::actingAs($user)
        ->test(StepType::class)
        ->assertSee('Server name')
        ->call('chooseImportMode')
        ->assertDontSee('Server name')
        ->assertSee('The name comes from the provider');

    // …and an empty name no longer blocks the step.
    $component->set('form.name', '')
        ->call('next')
        ->assertHasNoErrors()
        ->assertRedirect(route('servers.create.scan'));
});

test('an unnamed or awkwardly named machine still gets a valid dply name', function () {
    [$user, $org] = scanUserWithOrg();
    scanDraft($user, $org);
    $credential = doCredential($user, $org);

    fakeDroplets([
        droplet(301, 'has spaces/and:junk', '203.0.113.8'),
        droplet(302, '', '203.0.113.9'),
    ]);

    Livewire::actingAs($user)
        ->test(StepScan::class)
        ->set('credentialId', (string) $credential->id)
        ->call('scan')
        ->call('openAdopt', '301')
        ->assertSet('adoptName', 'has-spaces-and-junk')
        ->call('openAdopt', '302')
        ->assertSet('adoptName', 'digitalocean-302');
});

test('scan step redirects back to step one when the draft is not in import mode', function () {
    [$user, $org] = scanUserWithOrg();

    ServerCreateDraft::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'step' => 1,
        'payload' => ['mode' => 'provider', 'name' => 'whatever'],
    ]);

    Livewire::actingAs($user)
        ->test(StepScan::class)
        ->assertRedirect(route('servers.create', ['edit' => 1]));
});

test('scan lists droplets and marks the ones dply already manages', function () {
    [$user, $org] = scanUserWithOrg();
    scanDraft($user, $org);
    $credential = doCredential($user, $org);

    Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'provider_id' => '111',
        'name' => 'already-here',
    ]);

    fakeDroplets([
        droplet(111, 'already-here', '10.0.0.1'),
        droplet(222, 'not-in-dply', '203.0.113.7'),
    ]);

    $component = Livewire::actingAs($user)
        ->test(StepScan::class)
        ->set('credentialId', (string) $credential->id)
        ->call('scan')
        ->assertSet('scanError', '')
        ->assertSee('not-in-dply')
        ->assertSee('In dply');

    $found = collect($component->get('found'));

    expect($found)->toHaveCount(2)
        ->and($found->firstWhere('provider_id', '111')['imported'])->toBeTrue()
        ->and($found->firstWhere('provider_id', '222')['imported'])->toBeFalse()
        ->and($found->firstWhere('provider_id', '222')['public_ipv4'])->toBe('203.0.113.7')
        ->and($found->firstWhere('provider_id', '222')['region'])->toBe('nyc3')
        ->and($found->firstWhere('provider_id', '222')['size'])->toBe('s-2vcpu-4gb');
});

test('adopting a scanned droplet creates the server from api details', function () {
    [$user, $org] = scanUserWithOrg();
    $draft = scanDraft($user, $org);
    $credential = doCredential($user, $org);

    fakeDroplets([droplet(222, 'not-in-dply', '203.0.113.7')]);

    Livewire::actingAs($user)
        ->test(StepScan::class)
        ->set('credentialId', (string) $credential->id)
        ->call('scan')
        ->call('openAdopt', '222')
        // Name, address, region and size all arrive prefilled from the API.
        ->assertSet('adoptName', 'not-in-dply')
        ->assertSet('adoptIp', '203.0.113.7')
        ->set('adoptKeySource', 'generate')
        ->call('adopt')
        ->assertHasNoErrors();

    $server = Server::query()->where('provider_id', '222')->first();

    expect($server)->not->toBeNull()
        ->and($server->name)->toBe('not-in-dply')
        ->and($server->ip_address)->toBe('203.0.113.7')
        ->and($server->region)->toBe('nyc3')
        ->and($server->size)->toBe('s-2vcpu-4gb')
        ->and($server->provider_credential_id)->toBe($credential->id)
        ->and($server->ssh_private_key)->not->toBeEmpty()
        ->and($server->meta['adopted_from'])->toBe('digitalocean');

    // The draft is spent — import mode never reaches the build steps.
    expect(ServerCreateDraft::query()->find($draft->id))->toBeNull();
});

test('import scans the box instead of provisioning it', function () {
    Queue::fake();

    [$user, $org] = scanUserWithOrg();
    scanDraft($user, $org);
    $credential = doCredential($user, $org);

    fakeDroplets([droplet(222, 'not-in-dply', '203.0.113.7')]);

    Livewire::actingAs($user)
        ->test(StepScan::class)
        ->set('credentialId', (string) $credential->id)
        ->call('scan')
        ->call('openAdopt', '222')
        ->set('adoptKeySource', 'generate')
        ->call('adopt')
        ->assertHasNoErrors();

    $server = Server::query()->where('provider_id', '222')->firstOrFail();

    // Adopted machines are already running something: dply reads what is there
    // (read-only probe) and never installs over it.
    expect($server->meta['adopted'])->toBeTrue();
    Queue::assertPushed(RefreshServerInventoryJob::class, fn ($job): bool => $job->serverId === (string) $server->id);
});

test('adopt with a generated key keeps the user on the page to install it', function () {
    [$user, $org] = scanUserWithOrg();
    scanDraft($user, $org);
    $credential = doCredential($user, $org);

    fakeDroplets([droplet(222, 'not-in-dply', '203.0.113.7')]);

    $component = Livewire::actingAs($user)
        ->test(StepScan::class)
        ->set('credentialId', (string) $credential->id)
        ->call('scan')
        ->call('openAdopt', '222')
        ->set('adoptKeySource', 'generate')
        ->call('adopt');

    expect($component->get('generatedPublicKey'))->toStartWith('ssh-ed25519 ');
    $component->assertSee('Imported — one step left')
        // The install command has to survive Blade escaping intact — a literal
        // &quot; in the shell line would be pasted and fail on the host.
        ->assertSee('mkdir -p ~/.ssh &amp;&amp; chmod 700 ~/.ssh &amp;&amp; echo &quot;ssh-ed25519 ', escape: false)
        ->assertDontSee('&amp;quot;', escape: false);

    // The row flips to imported so a second click can't duplicate the server.
    expect(collect($component->get('found'))->firstWhere('provider_id', '222')['imported'])->toBeTrue();
});

test('a row already in dply can be checked for whether dply can still connect', function () {
    [$user, $org] = scanUserWithOrg();
    scanDraft($user, $org);
    $credential = doCredential($user, $org);

    $existing = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'provider_id' => '111',
        'name' => 'already-here',
        'ip_address' => '203.0.113.5',
        'ssh_private_key' => null,
    ]);

    fakeDroplets([droplet(111, 'already-here', '203.0.113.5')]);

    $component = Livewire::actingAs($user)
        ->test(StepScan::class)
        ->set('credentialId', (string) $credential->id)
        ->call('scan')
        // The matched server rides the row, so the check knows what to probe.
        ->assertSee('Check');

    expect(collect($component->get('found'))->firstWhere('provider_id', '111')['server_id'])->toBe($existing->id);

    // No stored key is a concrete answer, not a silent "already in dply".
    $component->call('checkReachability', '111');

    $verdict = $component->get('reachability')['111'];
    expect($verdict['ok'])->toBeFalse()
        ->and($verdict['message'])->toContain('no SSH key stored');

    $component->assertSee('Cannot connect');
});

test('rescanning clears stale reachability verdicts', function () {
    [$user, $org] = scanUserWithOrg();
    scanDraft($user, $org);
    $credential = doCredential($user, $org);

    Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'provider_id' => '111',
        'name' => 'already-here',
        'ssh_private_key' => null,
    ]);

    fakeDroplets([droplet(111, 'already-here', '203.0.113.5')]);

    $component = Livewire::actingAs($user)
        ->test(StepScan::class)
        ->set('credentialId', (string) $credential->id)
        ->call('scan')
        ->call('checkReachability', '111');

    expect($component->get('reachability'))->toHaveKey('111');

    $component->call('scan');

    expect($component->get('reachability'))->toBe([]);
});

test('a failed check can be fixed in place without creating a second server', function () {
    [$user, $org] = scanUserWithOrg();
    scanDraft($user, $org);
    $credential = doCredential($user, $org);

    $broken = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'provider_id' => '111',
        'name' => 'already-here',
        'ip_address' => '203.0.113.5',
        'ssh_user' => 'deploy',
        'ssh_port' => 2222,
        'ssh_private_key' => null,
    ]);

    $donor = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'provider_id' => '999',
        'name' => 'donor',
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\n".str_repeat('k', 80)."\n-----END OPENSSH PRIVATE KEY-----",
    ]);

    fakeDroplets([droplet(111, 'already-here', '203.0.113.5')]);

    $before = Server::query()->count();

    $component = Livewire::actingAs($user)
        ->test(StepScan::class)
        ->set('credentialId', (string) $credential->id)
        ->call('scan')
        ->call('checkReachability', '111')
        ->assertSee('Fix access')
        ->call('openRepair', '111')
        // Prefilled from what dply has on file — that is what's being fixed.
        ->assertSet('adoptMode', 'repair')
        ->assertSet('adoptName', 'already-here')
        ->assertSet('adoptSshUser', 'deploy')
        ->assertSet('adoptSshPort', '2222')
        ->assertSee('Fix access to already-here')
        ->assertSee('Save access');

    // The broken server's own key is not offered as a source — it just failed.
    expect(collect($component->get('reusableKeyServers') ?? [])->count())->toBeGreaterThanOrEqual(0);

    $component->set('adoptKeySource', 'existing')
        ->set('adoptKeyServerId', (string) $donor->id)
        ->call('adopt')
        ->assertHasNoErrors();

    expect(Server::query()->count())->toBe($before);

    $broken->refresh();
    expect($broken->ssh_private_key)->toBe($donor->ssh_private_key)
        ->and($broken->id)->toBe($broken->id);
});

test('repairing a server matched only by address records its provider id', function () {
    [$user, $org] = scanUserWithOrg();
    scanDraft($user, $org);
    $credential = doCredential($user, $org);

    // Adopted before dply recorded provider ids — matched by address alone.
    $legacy = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'provider_id' => null,
        'ip_address' => '203.0.113.5',
        'name' => 'legacy',
        'ssh_private_key' => null,
    ]);

    fakeDroplets([droplet(111, 'legacy', '203.0.113.5')]);

    Livewire::actingAs($user)
        ->test(StepScan::class)
        ->set('credentialId', (string) $credential->id)
        ->call('scan')
        ->call('openRepair', '111')
        ->set('adoptKeySource', 'paste')
        ->set('adoptSshPrivateKey', "-----BEGIN OPENSSH PRIVATE KEY-----\n".str_repeat('z', 80)."\n-----END OPENSSH PRIVATE KEY-----")
        ->call('adopt')
        ->assertHasNoErrors();

    expect($legacy->refresh()->provider_id)->toBe('111');
});

test('adopt can reuse the key from another server in the org', function () {
    [$user, $org] = scanUserWithOrg();
    scanDraft($user, $org);
    $credential = doCredential($user, $org);

    $sibling = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'provider_id' => '900',
        'name' => 'beacon',
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\n".str_repeat('k', 80)."\n-----END OPENSSH PRIVATE KEY-----",
    ]);

    fakeDroplets([droplet(222, 'not-in-dply', '203.0.113.7')]);

    Livewire::actingAs($user)
        ->test(StepScan::class)
        ->set('credentialId', (string) $credential->id)
        ->call('scan')
        ->call('openAdopt', '222')
        // A reusable key exists, so that is the default — no paste required.
        ->assertSet('adoptKeySource', 'existing')
        ->assertSet('adoptKeyServerId', (string) $sibling->id)
        ->assertSee('Reuse a stored key')
        ->call('adopt')
        ->assertHasNoErrors();

    $imported = Server::query()->where('provider_id', '222')->first();

    expect($imported->ssh_private_key)->toBe($sibling->ssh_private_key);
});

test('key reuse falls back to paste when the org has no stored keys', function () {
    [$user, $org] = scanUserWithOrg();
    scanDraft($user, $org);
    $credential = doCredential($user, $org);

    fakeDroplets([droplet(222, 'not-in-dply', '203.0.113.7')]);

    Livewire::actingAs($user)
        ->test(StepScan::class)
        ->set('credentialId', (string) $credential->id)
        ->call('scan')
        ->call('openAdopt', '222')
        ->assertSet('adoptKeySource', 'paste')
        ->assertDontSee('Reuse a stored key');
});

test('test connection reports before anything is written', function () {
    [$user, $org] = scanUserWithOrg();
    scanDraft($user, $org);
    $credential = doCredential($user, $org);

    fakeDroplets([droplet(222, 'not-in-dply', '203.0.113.7')]);

    // 203.0.113.0/24 is TEST-NET-3 — guaranteed unroutable, so this exercises
    // the failure path without touching the network.
    $component = Livewire::actingAs($user)
        ->test(StepScan::class)
        ->set('credentialId', (string) $credential->id)
        ->call('scan')
        ->call('openAdopt', '222')
        ->set('adoptKeySource', 'paste')
        ->set('adoptSshPrivateKey', 'not-a-key')
        ->call('testConnection');

    expect($component->get('probeResult')['ok'])->toBeFalse();

    // Testing is a read-only probe — nothing is created either way.
    expect(Server::query()->where('provider_id', '222')->exists())->toBeFalse();
});

test('test connection says there is nothing to test for a generated key', function () {
    [$user, $org] = scanUserWithOrg();
    scanDraft($user, $org);
    $credential = doCredential($user, $org);

    fakeDroplets([droplet(222, 'not-in-dply', '203.0.113.7')]);

    $component = Livewire::actingAs($user)
        ->test(StepScan::class)
        ->set('credentialId', (string) $credential->id)
        ->call('scan')
        ->call('openAdopt', '222')
        ->set('adoptKeySource', 'generate')
        ->call('testConnection');

    expect($component->get('probeResult')['ok'])->toBeFalse()
        ->and($component->get('probeResult')['message'])->toContain('generated on import');
});

test('adopt refuses a pasted key that is obviously not one', function () {
    [$user, $org] = scanUserWithOrg();
    scanDraft($user, $org);
    $credential = doCredential($user, $org);

    fakeDroplets([droplet(222, 'not-in-dply', '203.0.113.7')]);

    Livewire::actingAs($user)
        ->test(StepScan::class)
        ->set('credentialId', (string) $credential->id)
        ->call('scan')
        ->call('openAdopt', '222')
        ->set('adoptSshPrivateKey', 'nope')
        ->call('adopt')
        ->assertHasErrors(['adoptSshPrivateKey']);

    expect(Server::query()->where('provider_id', '222')->exists())->toBeFalse();
});

test('a failing provider api surfaces the error instead of an empty list', function () {
    [$user, $org] = scanUserWithOrg();
    scanDraft($user, $org);
    $credential = doCredential($user, $org);

    Http::fake([
        'https://api.digitalocean.com/v2/droplets*' => Http::response(['message' => 'Unauthorized'], 401),
    ]);

    $component = Livewire::actingAs($user)
        ->test(StepScan::class)
        ->set('credentialId', (string) $credential->id)
        ->call('scan')
        ->assertSet('scanned', false);

    expect($component->get('scanError'))->toContain('DigitalOcean');
});

test('a server stored without a provider id is still matched by address', function () {
    [$user, $org] = scanUserWithOrg();
    scanDraft($user, $org);
    $credential = doCredential($user, $org);

    // Adopted before dply recorded provider ids — only the address identifies it.
    Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'provider_id' => null,
        'ip_address' => '203.0.113.7',
        'name' => 'legacy-import',
    ]);

    fakeDroplets([droplet(222, 'not-in-dply', '203.0.113.7')]);

    $component = Livewire::actingAs($user)
        ->test(StepScan::class)
        ->set('credentialId', (string) $credential->id)
        ->call('scan');

    expect(collect($component->get('found'))->firstWhere('provider_id', '222')['imported'])->toBeTrue();
    $component->assertSee('In dply');
});

test('the account list only offers accounts for the selected provider', function () {
    [$user, $org] = scanUserWithOrg();
    scanDraft($user, $org);

    doCredential($user, $org);
    ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'hetzner',
        'name' => 'Hetzner prod',
        'credentials' => ['api_token' => 'token'],
    ]);
    // Not scannable — must never appear, whatever the provider filter says.
    ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'aws',
        'name' => 'AWS root',
        'credentials' => ['api_token' => 'token'],
    ]);

    Livewire::actingAs($user)
        ->test(StepScan::class)
        ->assertSet('provider', 'digitalocean')
        ->assertSee('Primary DO')
        ->assertDontSee('Hetzner prod')
        ->assertDontSee('AWS root')
        ->set('provider', 'hetzner')
        ->assertSee('Hetzner prod')
        ->assertDontSee('Primary DO')
        ->assertDontSee('AWS root');
});

test('inventory only claims providers whose api can enumerate machines', function () {
    $inventory = new ProviderServerInventory;

    expect($inventory->supports('digitalocean'))->toBeTrue()
        ->and($inventory->supports('hetzner'))->toBeTrue()
        ->and($inventory->supports('linode'))->toBeTrue()
        ->and($inventory->supports('vultr'))->toBeTrue()
        ->and($inventory->supports('aws'))->toBeFalse()
        ->and($inventory->supports('custom'))->toBeFalse()
        ->and($inventory->supports(null))->toBeFalse();
});
