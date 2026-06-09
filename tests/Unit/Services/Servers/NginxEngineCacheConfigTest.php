<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerWebserverCacheFeature;
use App\Models\User;
use App\Services\Servers\NginxEngineCacheConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders shared fastcgi and proxy cache paths from feature row', function (): void {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    $feature = ServerWebserverCacheFeature::findOrCreateFor(
        $server->id,
        ServerWebserverCacheFeature::WEBSERVER_NGINX,
    );
    $feature->update([
        'nginx_fcgi_zone_size_mb' => 128,
        'nginx_proxy_zone_size_mb' => 64,
        'nginx_zone_max_size_gb' => 4,
        'nginx_zone_inactive_minutes' => 120,
    ]);

    $contents = app(NginxEngineCacheConfig::class)->renderConfContents($feature->fresh());

    expect($contents)->toContain('fastcgi_cache_path');
    expect($contents)->toContain('proxy_cache_path');
    expect($contents)->toContain('keys_zone=dply_engine_fcgi:128m');
    expect($contents)->toContain('keys_zone=dply_engine_proxy:64m');
    expect($contents)->toContain('max_size=4g');
    expect($contents)->toContain('inactive=120m');
});

it('reads zone defaults from server webserver cache feature row', function (): void {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    $result = app(NginxEngineCacheConfig::class)->read($server);

    expect($result['values']['nginx_fcgi_zone_size_mb'])->toBe('100');
    expect($result['fcgi_zone'])->toBe('dply_engine_fcgi');
});
