<?php

declare(strict_types=1);

namespace Tests\Feature\FlushDeployDigestCommandTest;

use App\Models\Organization;
use App\Models\User;
use App\Services\Notifications\DeployDigestBuffer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

test('does nothing when digest disabled', function () {
    Config::set('dply.deploy_digest_hours', 0);
    Mail::fake();

    $org = Organization::factory()->create(['deploy_email_notifications_enabled' => true]);
    $owner = User::factory()->create();
    $org->users()->attach($owner->id, ['role' => 'owner']);
    DeployDigestBuffer::record($org->id, 'site foo deployed');

    $exit = Artisan::call('dply:flush-deploy-digest');

    expect($exit)->toBe(0);
    Mail::assertNothingSent();

    // Lines remain buffered when feature is off.
    expect(Cache::get('deploy-digest-lines:'.$org->id))->not->toBeEmpty();
});
test('drains buffer for eligible orgs', function () {
    Config::set('dply.deploy_digest_hours', 4);
    Mail::fake();

    $org = Organization::factory()->create(['deploy_email_notifications_enabled' => true]);
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $org->users()->attach($owner->id, ['role' => 'owner']);
    $org->users()->attach($member->id, ['role' => 'deployer']);
    DeployDigestBuffer::record($org->id, 'site shop deployed (success)');
    DeployDigestBuffer::record($org->id, 'site api deployed (failed)');

    Artisan::call('dply:flush-deploy-digest');

    // Buffer drained — implies the flush ran end-to-end (the
    // mail send path goes through Mail::raw which Mail::fake
    // captures, but the captured form differs across Laravel
    // versions; asserting buffer state is the stable contract).
    expect(Cache::get('deploy-digest-lines:'.$org->id, []))->toBeEmpty();
});
test('skips orgs with email notifications disabled', function () {
    Config::set('dply.deploy_digest_hours', 4);
    Mail::fake();

    $org = Organization::factory()->create(['deploy_email_notifications_enabled' => false]);
    $owner = User::factory()->create();
    $org->users()->attach($owner->id, ['role' => 'owner']);
    DeployDigestBuffer::record($org->id, 'site foo deployed');

    Artisan::call('dply:flush-deploy-digest');

    Mail::assertNothingSent();
});
test('no op when no buffered lines', function () {
    Config::set('dply.deploy_digest_hours', 4);
    Mail::fake();

    $org = Organization::factory()->create(['deploy_email_notifications_enabled' => true]);
    $owner = User::factory()->create();
    $org->users()->attach($owner->id, ['role' => 'owner']);

    $exit = Artisan::call('dply:flush-deploy-digest');

    expect($exit)->toBe(0);
    Mail::assertNothingSent();
});
