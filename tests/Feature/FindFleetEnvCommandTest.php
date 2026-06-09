<?php

declare(strict_types=1);

namespace Tests\Feature\FindFleetEnvCommandTest;

use App\Models\Server;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

test('finds a key across multiple sites', function () {
    $server = Server::factory()->create();
    Site::factory()->create([
        'server_id' => $server->id,
        'name' => 'alpha-site',
        'slug' => 'alpha',
        'env_file_content' => 'DATABASE_URL=postgres://a',
    ]);
    Site::factory()->create([
        'server_id' => $server->id,
        'name' => 'bravo-site',
        'slug' => 'bravo',
        'env_file_content' => "DATABASE_URL=postgres://b\nOTHER_KEY=irrelevant",
    ]);

    Artisan::call('dply:fleet:env-find', [
        'key' => 'DATABASE_URL',
        '--reveal' => true,
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['count'])->toBe(2);
    expect($decoded['matches'][0]['site_name'])->toBe('alpha-site');
    expect($decoded['matches'][0]['value'])->toBe('postgres://a');
    expect($decoded['matches'][1]['site_name'])->toBe('bravo-site');
});
test('prefix mode matches keys with same prefix', function () {
    $server = Server::factory()->create();
    Site::factory()->create([
        'server_id' => $server->id,
        'slug' => 'alpha',
        'env_file_content' => "AWS_REGION=us-east-1\nAWS_BUCKET=mybucket\nOTHER=x",
    ]);

    Artisan::call('dply:fleet:env-find', [
        'key' => 'AWS_',
        '--prefix' => true,
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['count'])->toBe(2);
    $keys = array_column($decoded['matches'], 'key');
    sort($keys);
    expect($keys)->toBe(['AWS_BUCKET', 'AWS_REGION']);
});
test('masks values by default', function () {
    $server = Server::factory()->create();
    Site::factory()->create([
        'server_id' => $server->id,
        'slug' => 'alpha',
        'env_file_content' => 'API_KEY=super-secret-value',
    ]);

    Artisan::call('dply:fleet:env-find', [
        'key' => 'API_KEY',
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    $this->assertStringNotContainsString('super-secret-value', json_encode($decoded));
    $this->assertStringContainsString('•', $decoded['matches'][0]['value']);
});
test('exits non zero on no matches', function () {
    $server = Server::factory()->create();
    Site::factory()->create(['server_id' => $server->id, 'slug' => 'alpha']);

    $exit = Artisan::call('dply:fleet:env-find', [
        'key' => 'NONEXISTENT',
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($exit)->toBe(1);
    expect($decoded['matches'])->toBe([]);
});
test('rejects empty key', function () {
    $exit = Artisan::call('dply:fleet:env-find', ['key' => '']);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('cannot be empty', $output);
});
