<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\ServerCreateDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class PruneServerCreateDraftsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_prunes_only_expired_drafts(): void
    {
        $expired = $this->makeDraft(now()->subDay(), 'old draft');
        $fresh = $this->makeDraft(now()->addDay(), 'fresh draft');
        $undated = $this->makeDraft(null, 'no expiry');

        $exit = Artisan::call('dply:prune-server-create-drafts');

        $this->assertSame(0, $exit);
        $this->assertNull(ServerCreateDraft::query()->find($expired->id));
        $this->assertNotNull(ServerCreateDraft::query()->find($fresh->id));
        $this->assertNotNull(ServerCreateDraft::query()->find($undated->id));
    }

    private function makeDraft(?\Carbon\Carbon $expiresAt, string $name): ServerCreateDraft
    {
        return ServerCreateDraft::query()->create([
            'user_id' => User::factory()->create()->id,
            'organization_id' => Organization::factory()->create()->id,
            'step' => 1,
            'payload' => ['name' => $name],
            'expires_at' => $expiresAt,
        ]);
    }

    public function test_no_op_when_nothing_expired(): void
    {
        $exit = Artisan::call('dply:prune-server-create-drafts');

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Deleted 0', Artisan::output());
    }
}
