<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Jobs\CheckServerHealthJob;
use App\Jobs\DeployGuestMetricsCallbackEnvJob;
use App\Jobs\InstallMetricsAgentJob;
use App\Jobs\RunServerInsightsJob;
use App\Jobs\RunSetupScriptJob;
use App\Jobs\SyncServerSystemdServicesJob;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Notifications\ServerProvisionedCredentialsNotification;
use App\Notifications\SiteDatabaseCredentialsNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Org toggles gate the credential emails — both default off, both
 * fire correctly when on, neither fires when off.
 */
class CredentialsEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_server_credentials_email_does_not_fire_when_toggle_off(): void
    {
        Notification::fake();
        Bus::fake([
            RunServerInsightsJob::class,
            CheckServerHealthJob::class,
            DeployGuestMetricsCallbackEnvJob::class,
            InstallMetricsAgentJob::class,
            SyncServerSystemdServicesJob::class,
        ]);

        [$user, $org, $server] = $this->makeServer(['email_server_credentials_enabled' => false]);

        RunSetupScriptJob::applyProvisionOutcomeToServer($server, true);

        Notification::assertNothingSent();
    }

    public function test_server_credentials_email_fires_on_provision_success_when_toggle_on(): void
    {
        Notification::fake();
        Bus::fake([
            RunServerInsightsJob::class,
            CheckServerHealthJob::class,
            DeployGuestMetricsCallbackEnvJob::class,
            InstallMetricsAgentJob::class,
            SyncServerSystemdServicesJob::class,
        ]);

        [$user, $org, $server] = $this->makeServer(['email_server_credentials_enabled' => true]);

        RunSetupScriptJob::applyProvisionOutcomeToServer($server, true);

        Notification::assertSentTo($user, ServerProvisionedCredentialsNotification::class);
    }

    public function test_server_credentials_email_does_not_fire_on_provision_failure(): void
    {
        Notification::fake();
        Bus::fake([
            RunServerInsightsJob::class,
            CheckServerHealthJob::class,
            DeployGuestMetricsCallbackEnvJob::class,
            InstallMetricsAgentJob::class,
            SyncServerSystemdServicesJob::class,
        ]);

        [$user, $org, $server] = $this->makeServer(['email_server_credentials_enabled' => true]);

        // Provision failure path — email should NOT fire even though
        // the toggle is on. The email represents "your server is ready
        // to use," not "your server tried and failed."
        RunSetupScriptJob::applyProvisionOutcomeToServer($server, false);

        Notification::assertNothingSent();
    }

    public function test_database_credentials_email_carries_full_payload_for_mysql(): void
    {
        $notif = new SiteDatabaseCredentialsNotification(
            site: $this->makeSite(),
            engine: 'mysql84',
            password: 'super-secret-pw',
            databaseName: 'dply_test',
            username: 'dply_test',
        );

        $mail = $notif->toMail($notif->site->user);
        $rendered = collect($mail->introLines)->implode("\n");

        $this->assertStringContainsString('mysql84', $rendered);
        $this->assertStringContainsString('Port: 3306', $rendered);
        $this->assertStringContainsString('dply_test', $rendered);
        $this->assertStringContainsString('super-secret-pw', $rendered);
    }

    public function test_database_credentials_email_for_sqlite_omits_credentials_block(): void
    {
        $notif = new SiteDatabaseCredentialsNotification(
            site: $this->makeSite(),
            engine: 'sqlite3',
            sqlitePath: '/home/dply/sites/example/database/database.sqlite',
        );

        $mail = $notif->toMail($notif->site->user);
        $rendered = collect($mail->introLines)->implode("\n");

        // Confirms the sqlite branch fires (no Port:, no Username:,
        // no Password: lines that the SQL branch would emit) and the
        // file-path note is present.
        $this->assertStringContainsString('SQLite', $rendered);
        $this->assertStringContainsString('database.sqlite', $rendered);
        $this->assertStringNotContainsString('Port:', $rendered);
        $this->assertStringNotContainsString('Username:', $rendered);
        $this->assertStringNotContainsString('Password:', $rendered);
    }

    public function test_postgres_email_uses_5432_default_port(): void
    {
        $notif = new SiteDatabaseCredentialsNotification(
            site: $this->makeSite(),
            engine: 'postgres17',
            password: 'pg-pw',
            databaseName: 'dply_pg',
            username: 'dply_pg',
        );

        $mail = $notif->toMail($notif->site->user);
        $rendered = collect($mail->introLines)->implode("\n");

        $this->assertStringContainsString('Port: 5432', $rendered);
    }

    /**
     * @param  array<string,mixed>  $orgAttrs
     * @return array{0:User,1:Organization,2:Server}
     */
    private function makeServer(array $orgAttrs = []): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create($orgAttrs);
        $org->users()->attach($user->id, ['role' => 'admin']);

        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'ip_address' => '203.0.113.10',
        ]);

        return [$user, $org, $server];
    }

    private function makeSite(): Site
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'admin']);

        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'ip_address' => '203.0.113.10',
        ]);

        return Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);
    }
}
