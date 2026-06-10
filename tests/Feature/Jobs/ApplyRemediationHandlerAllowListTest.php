<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs\ApplyRemediationHandlerAllowListTest;

use App\Jobs\ApplyRemediationJob;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Security regression guard for {@see ApplyRemediationJob}: a class-backed
 * `handler` is instantiated ONLY when it is in the catalog's allow-list
 * ({@see RemediationCatalog::handlerClasses()}). The handler below implements
 * RemediationActionInterface, so the `is_a()` interface guard alone would let it
 * run — only the allow-list `in_array()` check stops it. If that check is
 * removed, `apply()` runs and this test fails.
 */

test('it does not run a handler outside the catalog allow-list', function () {
    RogueHandler::$applied = false;

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $server = Server::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id]);

    $job = new ApplyRemediationJob(
        serverId: (string) $server->id,
        siteId: null,
        code: 'whatever',
        actionKey: 'whatever',
    );

    $job->handle(app(ExecuteRemoteTaskOnServer::class), new RogueCatalog);

    expect(RogueHandler::$applied)->toBeFalse();

    // It fell through to the (absent) script path and recorded a failure rather
    // than executing the un-allow-listed class.
    $this->assertDatabaseHas('console_actions', [
        'subject_id' => $server->id,
        'kind' => 'remediation_apply',
        'status' => 'failed',
    ]);
});
