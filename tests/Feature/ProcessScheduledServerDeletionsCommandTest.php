<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProcessScheduledServerDeletionsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_deletes_servers_whose_scheduled_deletion_is_due(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);

        $due = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'scheduled_deletion_at' => now()->subMinutes(5),
            'meta' => ['scheduled_deletion_reason' => 'cost cleanup'],
        ]);
        $future = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'scheduled_deletion_at' => now()->addDay(),
        ]);
        $unscheduled = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        $exit = Artisan::call('dply:process-scheduled-server-deletions');

        $this->assertSame(0, $exit);
        $this->assertNull(Server::query()->find($due->id));
        $this->assertNotNull(Server::query()->find($future->id));
        $this->assertNotNull(Server::query()->find($unscheduled->id));
    }

    public function test_no_op_when_nothing_due(): void
    {
        $user = User::factory()->create();
        Server::factory()->ready()->create([
            'user_id' => $user->id,
            'scheduled_deletion_at' => now()->addDay(),
        ]);

        $exit = Artisan::call('dply:process-scheduled-server-deletions');

        $this->assertSame(0, $exit);
        $this->assertSame('', trim(Artisan::output()));
    }

    public function test_records_audit_log_for_deletion(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);

        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'scheduled_deletion_at' => now()->subMinute(),
            'meta' => ['scheduled_deletion_reason' => 'org wind-down'],
        ]);
        $serverId = $server->id;

        Artisan::call('dply:process-scheduled-server-deletions');

        $audit = \DB::table('audit_logs')
            ->where('subject_type', Server::class)
            ->where('subject_id', $serverId)
            ->where('action', 'server.deleted')
            ->first();
        $this->assertNotNull($audit);
        $newValues = json_decode($audit->new_values, true);
        $this->assertSame('org wind-down', $newValues['reason'] ?? null);
        $this->assertTrue($newValues['scheduled_completion'] ?? false);
    }
}
