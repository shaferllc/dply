<?php

declare(strict_types=1);

namespace Tests\Feature\SiteEnvFixAllWarningsTest;

use App\Livewire\Sites\SiteEnvironment;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Sites\DotEnvFileParser;
use App\Services\Sites\SiteEnvValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function fixAllSite(string $env): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'ssh_private_key' => 'fake-key',
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Site::STATUS_NGINX_ACTIVE,
    ]);
    $site->forceFill(['env_file_content' => $env])->save();

    return [$user, $server, $site];
}

function envMap(Site $site): array
{
    return app(DotEnvFileParser::class)
        ->parse((string) ($site->fresh()->env_file_content ?? ''))['variables'];
}

test('fix all settles every auto-fixable warning in one write', function () {
    Queue::fake();

    [$user, $server, $site] = fixAllSite(implode("\n", [
        'APP_KEY=',
        'APP_DEBUG=true',
        'APP_ENV=local',
        'BROADCAST_CONNECTION=reverb',
        'REVERB_APP_KEY=',
        'REVERB_APP_ID=',
        'REVERB_APP_SECRET=',
        'DB_CONNECTION=pgsql',
        'DB_HOST=127.0.0.1',
        'DB_DATABASE=app',
        'DB_PASSWORD=',
    ]));

    Livewire::actingAs($user)
        ->test(SiteEnvironment::class, ['server' => $server, 'site' => $site])
        ->call('fixAllEnvWarnings');

    $map = envMap($site);

    // Generated where the value is the operator's to choose.
    expect($map['APP_KEY'])->toStartWith('base64:');
    expect($map['REVERB_APP_KEY'])->not->toBe('');
    expect($map['REVERB_APP_SECRET'])->not->toBe('');
    expect($map['REVERB_APP_ID'])->toMatch('/^\d{6}$/');
    expect($map['REVERB_APP_KEY'])->not->toBe($map['REVERB_APP_SECRET']);

    // Known-good constants.
    expect($map['APP_DEBUG'])->toBe('false');
    expect($map['APP_ENV'])->toBe('production');

    // Left alone: only the operator knows this one. Filling it with a guess
    // would hand them a database that silently fails to connect.
    expect($map['DB_PASSWORD'])->toBe('');
});

test('fix all clears the warnings it claimed it would', function () {
    Queue::fake();

    [$user, $server, $site] = fixAllSite(implode("\n", [
        'APP_KEY=',
        'BROADCAST_CONNECTION=reverb',
        'REVERB_APP_KEY=',
        'REVERB_APP_ID=',
        'REVERB_APP_SECRET=',
    ]));

    $before = app(SiteEnvValidator::class)->validate(envMap($site));
    expect($before)->not->toBeEmpty();

    Livewire::actingAs($user)
        ->test(SiteEnvironment::class, ['server' => $server, 'site' => $site])
        ->call('fixAllEnvWarnings');

    $remaining = collect(app(SiteEnvValidator::class)->validate(envMap($site)))
        ->pluck('key')
        ->all();

    expect($remaining)->not->toContain('APP_KEY');
    expect($remaining)->not->toContain('REVERB_APP_KEY');
    expect($remaining)->not->toContain('REVERB_APP_ID');
    expect($remaining)->not->toContain('REVERB_APP_SECRET');
});

test('a suppressed warning is never silently fixed', function () {
    Queue::fake();

    [$user, $server, $site] = fixAllSite("APP_KEY=\nAPP_DEBUG=true");
    $site->forceFill(['meta' => ['suppressed_env_warnings' => ['APP_DEBUG']]])->save();

    Livewire::actingAs($user)
        ->test(SiteEnvironment::class, ['server' => $server, 'site' => $site])
        ->call('fixAllEnvWarnings');

    $map = envMap($site);

    expect($map['APP_KEY'])->toStartWith('base64:');
    // Ignored by the operator — the bulk action must respect that.
    expect($map['APP_DEBUG'])->toBe('true');
});

test('fix all is a no-op when nothing is auto-fixable', function () {
    Queue::fake();

    [$user, $server, $site] = fixAllSite('APP_KEY=base64:'.base64_encode(random_bytes(32))."\nAPP_ENV=production\nDB_CONNECTION=pgsql\nDB_HOST=db\nDB_DATABASE=app\nDB_PASSWORD=");

    $before = (string) $site->fresh()->env_file_content;

    Livewire::actingAs($user)
        ->test(SiteEnvironment::class, ['server' => $server, 'site' => $site])
        ->call('fixAllEnvWarnings');

    expect((string) $site->fresh()->env_file_content)->toBe($before);
});

test('the auto-fixable key list drives the button count', function () {
    [$user, $server, $site] = fixAllSite(implode("\n", [
        'APP_KEY=',
        'BROADCAST_CONNECTION=reverb',
        'REVERB_APP_KEY=',
        'REVERB_APP_ID=',
        'REVERB_APP_SECRET=',
        'DB_CONNECTION=pgsql',
        'DB_HOST=db',
        'DB_DATABASE=app',
        'DB_PASSWORD=',
    ]));

    $keys = Livewire::actingAs($user)
        ->test(SiteEnvironment::class, ['server' => $server, 'site' => $site])
        ->instance()
        ->autoFixableEnvWarningKeys(app(SiteEnvValidator::class), app(DotEnvFileParser::class));

    expect($keys)->toContain('APP_KEY', 'REVERB_APP_KEY', 'REVERB_APP_ID', 'REVERB_APP_SECRET');
    expect($keys)->not->toContain('DB_PASSWORD');
    // No duplicates — the count is rendered on the button.
    expect($keys)->toBe(array_values(array_unique($keys)));
});
