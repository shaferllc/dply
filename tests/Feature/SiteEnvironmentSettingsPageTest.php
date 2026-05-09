<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\PushSiteEnvJob;
use App\Jobs\SyncEnvFromServerJob;
use App\Livewire\Sites\Settings as SitesSettings;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Sites\DotEnvFileParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class SiteEnvironmentSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_add_env_var_writes_cache_and_dispatches_push_job(): void
    {
        Queue::fake();
        [$user, $server, $site] = $this->makeUserSite();

        Livewire::actingAs($user)
            ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
            ->set('new_env_key', 'API_KEY')
            ->set('new_env_value', 'super-secret')
            ->call('addEnvVar')
            ->assertSet('new_env_key', '')
            ->assertSet('new_env_value', '');

        $site->refresh();
        $this->assertSame(['API_KEY' => 'super-secret'], $this->parsed($site));
        $this->assertSame('local-edit', $site->env_cache_origin);
        Queue::assertPushed(PushSiteEnvJob::class, fn ($job) => $job->siteId === $site->id);
    }

    public function test_add_env_var_skips_push_for_unsupported_runtime(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_APP_PLATFORM],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        Livewire::actingAs($user)
            ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
            ->set('new_env_key', 'API_KEY')
            ->set('new_env_value', 'x')
            ->call('addEnvVar');

        $this->assertSame(['API_KEY' => 'x'], $this->parsed($site->fresh()));
        Queue::assertNotPushed(PushSiteEnvJob::class);
    }

    public function test_add_env_var_validates_key_format(): void
    {
        [$user, $server, $site] = $this->makeUserSite();

        Livewire::actingAs($user)
            ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
            ->set('new_env_key', 'lower-case')
            ->set('new_env_value', 'x')
            ->call('addEnvVar')
            ->assertHasErrors(['new_env_key']);

        $this->assertSame([], $this->parsed($site->fresh()));
    }

    public function test_bulk_import_merges_and_overwrites_then_auto_pushes(): void
    {
        Queue::fake();
        [$user, $server, $site] = $this->makeUserSite([
            'env_file_content' => "KEEP=k\nOVERRIDE=old",
        ]);

        Livewire::actingAs($user)
            ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
            ->set('bulk_env_input', "OVERRIDE=new\nFRESH=val\n")
            ->call('bulkImportEnvVars')
            ->assertSet('bulk_env_input', '');

        $this->assertSame([
            'FRESH' => 'val',
            'KEEP' => 'k',
            'OVERRIDE' => 'new',
        ], $this->parsed($site->fresh()));
        Queue::assertPushed(PushSiteEnvJob::class);
    }

    public function test_bulk_import_surfaces_parser_errors(): void
    {
        [$user, $server, $site] = $this->makeUserSite();

        Livewire::actingAs($user)
            ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
            ->set('bulk_env_input', "MALFORMED_LINE\n")
            ->call('bulkImportEnvVars')
            ->assertHasErrors(['bulk_env_input']);

        $this->assertSame([], $this->parsed($site->fresh()));
    }

    public function test_edit_env_var_pulls_value_into_form(): void
    {
        [$user, $server, $site] = $this->makeUserSite([
            'env_file_content' => 'DB_PASSWORD=hunter2',
        ]);

        Livewire::actingAs($user)
            ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
            ->call('editEnvVar', 'DB_PASSWORD')
            ->assertSet('editing_env_key', 'DB_PASSWORD')
            ->assertSet('editing_env_value', 'hunter2');
    }

    public function test_add_env_var_with_comment_round_trips(): void
    {
        Queue::fake();
        [$user, $server, $site] = $this->makeUserSite();

        Livewire::actingAs($user)
            ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
            ->set('new_env_key', 'STRIPE_KEY')
            ->set('new_env_value', 'sk_live_abc')
            ->set('new_env_comment', 'rotate quarterly')
            ->call('addEnvVar')
            ->assertSet('new_env_comment', '');

        $blob = (string) $site->fresh()->env_file_content;
        $this->assertStringContainsString("# rotate quarterly\nSTRIPE_KEY=sk_live_abc", $blob);
    }

    public function test_bulk_import_preserves_comments_above_keys(): void
    {
        Queue::fake();
        [$user, $server, $site] = $this->makeUserSite();

        $paste = "# Database settings\nDB_PASSWORD=hunter2\n\n# free-floating, no key after — dropped\n\nAPP_NAME=demo\n";

        Livewire::actingAs($user)
            ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
            ->set('bulk_env_input', $paste)
            ->call('bulkImportEnvVars');

        $blob = (string) $site->fresh()->env_file_content;
        $this->assertStringContainsString("# Database settings\nDB_PASSWORD=hunter2", $blob);
        $this->assertStringContainsString('APP_NAME=demo', $blob);
        // Free-floating comment was dropped (it was followed by a blank line,
        // breaking the comment-to-key association).
        $this->assertStringNotContainsString('free-floating', $blob);
    }

    public function test_edit_env_var_pulls_comment_into_form(): void
    {
        [$user, $server, $site] = $this->makeUserSite([
            'env_file_content' => "# rotate quarterly\nSTRIPE_KEY=sk_live\n",
        ]);

        Livewire::actingAs($user)
            ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
            ->call('editEnvVar', 'STRIPE_KEY')
            ->assertSet('editing_env_comment', 'rotate quarterly');
    }

    public function test_save_edited_env_var_writes_back_and_auto_pushes(): void
    {
        Queue::fake();
        [$user, $server, $site] = $this->makeUserSite([
            'env_file_content' => 'APP_NAME=old',
        ]);

        Livewire::actingAs($user)
            ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
            ->call('editEnvVar', 'APP_NAME')
            ->set('editing_env_value', 'new')
            ->call('saveEditedEnvVar')
            ->assertSet('editing_env_key', null);

        $this->assertSame(['APP_NAME' => 'new'], $this->parsed($site->fresh()));
        Queue::assertPushed(PushSiteEnvJob::class);
    }

    public function test_remove_env_var_deletes_key_and_auto_pushes(): void
    {
        Queue::fake();
        [$user, $server, $site] = $this->makeUserSite([
            'env_file_content' => "A=1\nB=2",
        ]);

        Livewire::actingAs($user)
            ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
            ->call('removeEnvVar', 'A');

        $this->assertSame(['B' => '2'], $this->parsed($site->fresh()));
        Queue::assertPushed(PushSiteEnvJob::class);
    }

    public function test_confirm_remove_env_var_opens_modal_without_deleting(): void
    {
        // The trash button now goes through a confirm step. Calling
        // confirmRemoveEnvVar must NOT mutate the cache or dispatch a push;
        // it just flips the modal state. The actual delete fires when the
        // operator clicks Confirm in the modal (which dispatches removeEnvVar).
        Queue::fake();
        [$user, $server, $site] = $this->makeUserSite([
            'env_file_content' => "A=1\nB=2",
        ]);

        Livewire::actingAs($user)
            ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
            ->call('confirmRemoveEnvVar', 'A')
            ->assertSet('showConfirmActionModal', true)
            ->assertSet('confirmActionModalMethod', 'removeEnvVar')
            ->assertSet('confirmActionModalArguments', ['A'])
            ->assertSet('confirmActionModalDestructive', true);

        // Cache untouched, no push dispatched yet.
        $this->assertSame(['A' => '1', 'B' => '2'], $this->parsed($site->fresh()));
        Queue::assertNotPushed(PushSiteEnvJob::class);
    }

    public function test_confirm_modal_completion_actually_deletes(): void
    {
        Queue::fake();
        [$user, $server, $site] = $this->makeUserSite([
            'env_file_content' => "A=1\nB=2",
        ]);

        Livewire::actingAs($user)
            ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
            ->call('confirmRemoveEnvVar', 'A')
            ->call('confirmActionModal');

        $this->assertSame(['B' => '2'], $this->parsed($site->fresh()));
        Queue::assertPushed(PushSiteEnvJob::class);
    }

    public function test_manual_push_method_still_dispatches_job(): void
    {
        // The Push button was removed in favor of auto-push, but the
        // pushEnvToServer Livewire method stays callable as the manual
        // recovery path (and is what CLI / future "Retry" affordances
        // route through).
        Queue::fake();
        [$user, $server, $site] = $this->makeUserSite([
            'env_file_content' => 'A=1',
        ]);

        Livewire::actingAs($user)
            ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
            ->call('pushEnvToServer');

        Queue::assertPushed(PushSiteEnvJob::class, fn ($job) => $job->siteId === $site->id);
    }

    public function test_auto_sync_first_visit_dispatches_when_cache_empty(): void
    {
        Queue::fake();
        [$user, $server, $site] = $this->makeUserSite();

        // Simulate the wire:init fire-after-render call.
        Livewire::actingAs($user)
            ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
            ->call('autoSyncIfFirstVisit');

        Queue::assertPushed(SyncEnvFromServerJob::class, fn ($job) => $job->siteId === $site->id);
    }

    public function test_auto_sync_first_visit_no_op_when_cache_has_content(): void
    {
        Queue::fake();
        [$user, $server, $site] = $this->makeUserSite([
            'env_file_content' => 'A=1',
        ]);

        Livewire::actingAs($user)
            ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
            ->call('autoSyncIfFirstVisit');

        Queue::assertNotPushed(SyncEnvFromServerJob::class);
    }

    public function test_auto_sync_first_visit_no_op_when_origin_already_set(): void
    {
        Queue::fake();
        [$user, $server, $site] = $this->makeUserSite();
        // Cache might be empty BUT origin='local-edit' means the operator
        // has explicitly cleared it; we mustn't replace that with server data.
        $site->forceFill(['env_cache_origin' => 'local-edit'])->save();

        Livewire::actingAs($user)
            ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
            ->call('autoSyncIfFirstVisit');

        Queue::assertNotPushed(SyncEnvFromServerJob::class);
    }

    public function test_auto_sync_first_visit_no_op_for_unsupported_runtime(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_APP_PLATFORM],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        Livewire::actingAs($user)
            ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
            ->call('autoSyncIfFirstVisit');

        Queue::assertNotPushed(SyncEnvFromServerJob::class);
    }

    public function test_manual_push_no_op_for_unsupported_runtime(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_APP_PLATFORM],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        Livewire::actingAs($user)
            ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
            ->call('pushEnvToServer');

        Queue::assertNotPushed(PushSiteEnvJob::class);
    }

    public function test_toggle_reveal_env_var_flips_state(): void
    {
        [$user, $server, $site] = $this->makeUserSite([
            'env_file_content' => 'A=1',
        ]);

        Livewire::actingAs($user)
            ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
            ->call('toggleRevealEnvVar', 'A')
            ->assertSet('revealed_env_keys', ['A'])
            ->call('toggleRevealEnvVar', 'A')
            ->assertSet('revealed_env_keys', []);
    }

    public function test_sync_env_from_server_dispatches_job(): void
    {
        Queue::fake();
        [$user, $server, $site] = $this->makeUserSite();

        Livewire::actingAs($user)
            ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
            ->call('syncEnvFromServer');

        Queue::assertPushed(SyncEnvFromServerJob::class, fn ($job) => $job->siteId === $site->id);
    }

    public function test_save_env_file_path_stores_absolute_override(): void
    {
        // Path saves now auto-push, so the job must be faked or a real SSH
        // would be attempted by the dispatched job.
        Queue::fake();
        [$user, $server, $site] = $this->makeUserSite();

        Livewire::actingAs($user)
            ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
            ->set('env_file_path_override', '/etc/dply/jobs.env')
            ->call('saveEnvFilePath')
            ->assertHasNoErrors();

        $this->assertSame('/etc/dply/jobs.env', $site->fresh()->env_file_path);
        $this->assertSame('/etc/dply/jobs.env', $site->fresh()->effectiveEnvFilePath());
        Queue::assertPushed(PushSiteEnvJob::class);
    }

    public function test_save_env_file_path_rejects_relative_path(): void
    {
        [$user, $server, $site] = $this->makeUserSite();

        Livewire::actingAs($user)
            ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
            ->set('env_file_path_override', 'etc/dply/jobs.env')
            ->call('saveEnvFilePath')
            ->assertHasErrors(['env_file_path_override']);

        $this->assertNull($site->fresh()->env_file_path);
    }

    public function test_relocate_env_outside_docroot_sets_path_and_dispatches_push(): void
    {
        Queue::fake();
        [$user, $server, $site] = $this->makeUserSite();

        Livewire::actingAs($user)
            ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
            ->call('relocateEnvOutsideDocroot');

        $expected = '/etc/dply/'.$site->slug.'.env';
        $this->assertSame($expected, $site->fresh()->env_file_path);
        Queue::assertPushed(PushSiteEnvJob::class, fn ($job) => $job->siteId === $site->id);
    }

    public function test_save_env_file_path_blank_clears_override(): void
    {
        Queue::fake();
        [$user, $server, $site] = $this->makeUserSite();
        $site->forceFill(['env_file_path' => '/etc/dply/old.env'])->save();

        Livewire::actingAs($user)
            ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
            ->set('env_file_path_override', '')
            ->call('saveEnvFilePath');

        $this->assertNull($site->fresh()->env_file_path);
    }

    public function test_sync_env_from_server_no_op_for_unsupported_runtime(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        // App-Platform host has no server-side .env, so sync should be refused.
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_APP_PLATFORM],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        Livewire::actingAs($user)
            ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
            ->call('syncEnvFromServer');

        Queue::assertNotPushed(SyncEnvFromServerJob::class);
    }

    /**
     * @param  array<string, mixed>  $siteAttrs
     * @return array{0: User, 1: Server, 2: Site}
     */
    private function makeUserSite(array $siteAttrs = []): array
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
        $site = Site::factory()->create(array_merge([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ], $siteAttrs));

        return [$user, $server, $site];
    }

    /**
     * @return array<string, string>
     */
    private function parsed(Site $site): array
    {
        $vars = app(DotEnvFileParser::class)->parse((string) ($site->env_file_content ?? ''))['variables'];
        ksort($vars);

        return $vars;
    }
}
