<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Deploy\ServerlessEnvironmentPreparerTest;

use App\Models\Site;
use App\Modules\Deploy\Services\ServerlessEnvironmentPreparer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/serverless-env-'.uniqid();
    File::ensureDirectoryExists($this->dir);
});
afterEach(function () {
    File::deleteDirectory($this->dir);
});
test('it seeds managed env from the repo and mints an app key', function () {
    File::put($this->dir.'/.env', "APP_ENV=production\nLOG_CHANNEL=stderr\n");
    $site = Site::factory()->create(['env_file_content' => null]);

    (new ServerlessEnvironmentPreparer)->prepare($site, $this->dir, true);

    $managed = (string) $site->fresh()->env_file_content;
    $this->assertStringContainsString('APP_ENV=production', $managed);
    expect($managed)->toMatch('/APP_KEY=base64:.+/');

    // The artifact's .env carries the managed environment.
    $this->assertStringContainsString('APP_KEY=base64:', (string) file_get_contents($this->dir.'/.env'));
});
test('it injects the command secret for background ticks', function () {
    $site = Site::factory()->create(['env_file_content' => null]);

    (new ServerlessEnvironmentPreparer)->prepare($site, $this->dir, true);

    // The baked secret is the site's stable command secret — not the
    // operator-rotatable webhook_secret.
    $commandSecret = $site->fresh()->ensureServerlessCommandSecret();
    $this->assertNotSame('', $commandSecret);
    $this->assertStringContainsString('DPLY_COMMAND_SECRET='.$commandSecret, (string) $site->fresh()->env_file_content);
});
test('the command secret survives a webhook secret rotation', function () {
    $site = Site::factory()->create(['env_file_content' => null]);
    $commandSecret = $site->ensureServerlessCommandSecret();

    // Operator regenerates the webhook secret, then redeploys.
    $site->update(['webhook_secret' => 'a-freshly-rotated-webhook-secret']);
    (new ServerlessEnvironmentPreparer)->prepare($site->fresh(), $this->dir, true);

    $managed = (string) $site->fresh()->env_file_content;
    $this->assertStringContainsString('DPLY_COMMAND_SECRET='.$commandSecret, $managed);
    $this->assertStringNotContainsString('a-freshly-rotated-webhook-secret', $managed);
});
test('it overwrites a stale command secret', function () {
    // A stale DPLY_COMMAND_SECRET committed in the repo .env must be
    // forced to the site's managed command secret, not kept.
    $site = Site::factory()->create([
        'env_file_content' => "APP_ENV=production\nDPLY_COMMAND_SECRET=stale-old-value\n",
    ]);

    (new ServerlessEnvironmentPreparer)->prepare($site, $this->dir, true);

    $managed = (string) $site->fresh()->env_file_content;
    $this->assertStringContainsString('DPLY_COMMAND_SECRET='.$site->fresh()->ensureServerlessCommandSecret(), $managed);
    $this->assertStringNotContainsString('stale-old-value', $managed);
});
test('it injects a stable log ingest secret', function () {
    $site = Site::factory()->create(['env_file_content' => null]);

    (new ServerlessEnvironmentPreparer)->prepare($site, $this->dir, true);

    $managed = (string) $site->fresh()->env_file_content;
    expect($managed)->toMatch('/DPLY_LOG_INGEST_SECRET=[a-f0-9]{48}/');

    // The secret is persisted on the site and stable across deploys.
    $secret = data_get($site->fresh()->meta, 'serverless.log_ingest_secret');
    expect($secret)->not->toBeEmpty();

    (new ServerlessEnvironmentPreparer)->prepare($site->fresh(), $this->dir, true);
    expect(data_get($site->fresh()->meta, 'serverless.log_ingest_secret'))->toBe($secret);
});
test('it skips the ingest url when no public url is configured', function () {
    // No DPLY_PUBLIC_APP_URL — a function on DigitalOcean could not reach
    // a local APP_URL, so no ingest URL is injected.
    config(['dply.public_app_url' => null]);
    $site = Site::factory()->create(['env_file_content' => null]);

    (new ServerlessEnvironmentPreparer)->prepare($site, $this->dir, true);

    $this->assertStringNotContainsString('DPLY_LOG_INGEST_URL=', (string) $site->fresh()->env_file_content);
});
test('it builds the ingest url from the public app url', function () {
    // A bare hostname (a common DPLY_PUBLIC_APP_URL value) gets a scheme.
    config(['dply.public_app_url' => 'dply.tunnel.example']);
    $site = Site::factory()->create(['env_file_content' => null]);

    (new ServerlessEnvironmentPreparer)->prepare($site, $this->dir, true);

    $this->assertStringContainsString(
        'DPLY_LOG_INGEST_URL=https://dply.tunnel.example/hooks/functions/'.$site->id.'/log',
        (string) $site->fresh()->env_file_content,
    );
});
test('it keeps an existing app key', function () {
    $existing = "APP_ENV=production\nAPP_KEY=base64:keepme0000000000000000000000000000000000000=\n";
    $site = Site::factory()->create(['env_file_content' => $existing]);

    (new ServerlessEnvironmentPreparer)->prepare($site, $this->dir, true);

    $managed = (string) $site->fresh()->env_file_content;
    expect(substr_count($managed, 'APP_KEY='))->toBe(1);
    $this->assertStringContainsString('APP_KEY=base64:keepme', $managed);
});
test('a non laravel function gets no app key', function () {
    File::put($this->dir.'/.env', "PORT=3000\n");
    $site = Site::factory()->create(['env_file_content' => null]);

    (new ServerlessEnvironmentPreparer)->prepare($site, $this->dir, false);

    $managed = (string) $site->fresh()->env_file_content;
    $this->assertStringContainsString('PORT=3000', $managed);
    $this->assertStringNotContainsString('APP_KEY', $managed);
});
test('managed env is authoritative over the repo env', function () {
    File::put($this->dir.'/.env', "FROM_REPO=1\n");
    $site = Site::factory()->create([
        'env_file_content' => "APP_KEY=base64:set\nMANAGED=1\n",
    ]);

    (new ServerlessEnvironmentPreparer)->prepare($site, $this->dir, true);

    $built = (string) file_get_contents($this->dir.'/.env');
    $this->assertStringContainsString('MANAGED=1', $built);
    $this->assertStringNotContainsString('FROM_REPO', $built);
});
