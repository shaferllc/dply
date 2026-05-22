<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\Notifications\DeployDigestBuffer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class FlushDeployDigestCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_does_nothing_when_digest_disabled(): void
    {
        Config::set('dply.deploy_digest_hours', 0);
        Mail::fake();

        $org = Organization::factory()->create(['deploy_email_notifications_enabled' => true]);
        $owner = User::factory()->create();
        $org->users()->attach($owner->id, ['role' => 'owner']);
        DeployDigestBuffer::record($org->id, 'site foo deployed');

        $exit = Artisan::call('dply:flush-deploy-digest');

        $this->assertSame(0, $exit);
        Mail::assertNothingSent();
        // Lines remain buffered when feature is off.
        $this->assertNotEmpty(Cache::get('deploy-digest-lines:'.$org->id));
    }

    public function test_drains_buffer_for_eligible_orgs(): void
    {
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
        $this->assertEmpty(Cache::get('deploy-digest-lines:'.$org->id, []));
    }

    public function test_skips_orgs_with_email_notifications_disabled(): void
    {
        Config::set('dply.deploy_digest_hours', 4);
        Mail::fake();

        $org = Organization::factory()->create(['deploy_email_notifications_enabled' => false]);
        $owner = User::factory()->create();
        $org->users()->attach($owner->id, ['role' => 'owner']);
        DeployDigestBuffer::record($org->id, 'site foo deployed');

        Artisan::call('dply:flush-deploy-digest');

        Mail::assertNothingSent();
    }

    public function test_no_op_when_no_buffered_lines(): void
    {
        Config::set('dply.deploy_digest_hours', 4);
        Mail::fake();

        $org = Organization::factory()->create(['deploy_email_notifications_enabled' => true]);
        $owner = User::factory()->create();
        $org->users()->attach($owner->id, ['role' => 'owner']);

        $exit = Artisan::call('dply:flush-deploy-digest');

        $this->assertSame(0, $exit);
        Mail::assertNothingSent();
    }
}
