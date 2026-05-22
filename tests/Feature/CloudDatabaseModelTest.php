<?php

declare(strict_types=1);

namespace Tests\Feature\CloudDatabaseModelTest;
use App\Models\CloudDatabase;
use App\Models\Organization;
use App\Models\Site;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('factory creates a provisioning postgres database', function () {
    $db = CloudDatabase::factory()->create();

    expect($db->engine)->toBe(CloudDatabase::ENGINE_POSTGRES);
    expect($db->status)->toBe(CloudDatabase::STATUS_PROVISIONING);
    expect($db->backend)->toBe(CloudDatabase::BACKEND_DIGITALOCEAN);
    expect($db->isActive())->toBeFalse();
});
test('connection is encrypted at rest', function () {
    $db = CloudDatabase::factory()->active()->create();

    $raw = \DB::table('cloud_databases')->where('id', $db->id)->value('connection');
    expect($raw)->toBeString();
    $this->assertStringNotContainsString('secret-pass', $raw);

    expect($db->fresh()->connection['password'])->toBe('secret-pass');
});
test('size tier maps to do size slug', function () {
    expect(CloudDatabase::factory()->make(['size' => 'small'])->backendSizeSlug())->toBe('db-s-1vcpu-1gb');
    expect(CloudDatabase::factory()->make(['size' => 'medium'])->backendSizeSlug())->toBe('db-s-1vcpu-2gb');
    expect(CloudDatabase::factory()->make(['size' => 'large'])->backendSizeSlug())->toBe('db-s-2vcpu-4gb');
    expect(CloudDatabase::factory()->make(['size' => 'bogus'])->backendSizeSlug())->toBe('db-s-1vcpu-1gb');
});
test('engine maps to do engine slug', function () {
    expect(CloudDatabase::factory()->make(['engine' => 'postgres'])->backendEngineSlug())->toBe('pg');
    expect(CloudDatabase::factory()->make(['engine' => 'mysql'])->backendEngineSlug())->toBe('mysql');
    expect(CloudDatabase::factory()->make(['engine' => 'redis'])->backendEngineSlug())->toBe('redis');
});
test('postgres connection env vars', function () {
    $db = CloudDatabase::factory()->active()->create();

    expect($db->connectionEnvVars())->toBe([
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => 'db.example.ondigitalocean.com',
        'DB_PORT' => '25060',
        'DB_DATABASE' => 'defaultdb',
        'DB_USERNAME' => 'doadmin',
        'DB_PASSWORD' => 'secret-pass',
    ]);
});
test('mysql connection env vars use mysql driver', function () {
    $db = CloudDatabase::factory()->mysql()->active()->create();

    expect($db->connectionEnvVars()['DB_CONNECTION'])->toBe('mysql');
});
test('redis connection env vars', function () {
    $db = CloudDatabase::factory()->redis()->active()->create();

    expect($db->connectionEnvVars())->toBe([
        'REDIS_HOST' => 'db.example.ondigitalocean.com',
        'REDIS_PORT' => '25060',
        'REDIS_PASSWORD' => 'secret-pass',
    ]);
});
test('connection env vars empty when not provisioned', function () {
    $db = CloudDatabase::factory()->create();

    expect($db->connectionEnvVars())->toBe([]);
});
test('connection env keys per engine', function () {
    expect(CloudDatabase::factory()->make()->connectionEnvKeys())->toContain('DB_HOST');
    expect(CloudDatabase::factory()->redis()->make()->connectionEnvKeys())->toContain('REDIS_HOST');
});
test('sites relation via pivot', function () {
    $db = CloudDatabase::factory()->create();
    $org = Organization::factory()->create();
    $site = Site::factory()->create(['organization_id' => $org->id]);

    $db->sites()->attach($site->id);

    expect($db->fresh()->sites->contains($site->id))->toBeTrue();
    $this->assertDatabaseHas('cloud_database_site', [
        'cloud_database_id' => $db->id,
        'site_id' => $site->id,
    ]);
});
