<?php

declare(strict_types=1);

namespace Tests\Feature\PruneServerCreateDraftsCommandTest;
use App\Models\Organization;
use App\Models\ServerCreateDraft;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('prunes only expired drafts', function () {
    $expired = makeDraft(now()->subDay(), 'old draft');
    $fresh = makeDraft(now()->addDay(), 'fresh draft');
    $undated = makeDraft(null, 'no expiry');

    $exit = Artisan::call('dply:prune-server-create-drafts');

    expect($exit)->toBe(0);
    expect(ServerCreateDraft::query()->find($expired->id))->toBeNull();
    expect(ServerCreateDraft::query()->find($fresh->id))->not->toBeNull();
    expect(ServerCreateDraft::query()->find($undated->id))->not->toBeNull();
});
function makeDraft(?Carbon $expiresAt, string $name): ServerCreateDraft
{
    return ServerCreateDraft::query()->create([
        'user_id' => User::factory()->create()->id,
        'organization_id' => Organization::factory()->create()->id,
        'step' => 1,
        'payload' => ['name' => $name],
        'expires_at' => $expiresAt,
    ]);
}
test('no op when nothing expired', function () {
    $exit = Artisan::call('dply:prune-server-create-drafts');

    expect($exit)->toBe(0);
    $this->assertStringContainsString('Deleted 0', Artisan::output());
});
