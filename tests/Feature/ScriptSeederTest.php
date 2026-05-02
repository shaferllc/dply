<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Script;
use App\Models\User;
use Database\Seeders\ScriptSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScriptSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_default_scripts_for_seed_users_organization(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);

        $this->seed(ScriptSeeder::class);

        $this->assertDatabaseHas('scripts', [
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'name' => ScriptSeeder::NAME_RELEASE_CONTEXT,
            'source' => Script::SOURCE_USER_CREATED,
        ]);
        $this->assertDatabaseHas('scripts', [
            'organization_id' => $org->id,
            'name' => ScriptSeeder::NAME_COMPOSER_PRODUCTION,
        ]);
        $this->assertDatabaseHas('scripts', [
            'organization_id' => $org->id,
            'name' => ScriptSeeder::NAME_DISK_USAGE,
        ]);

        $org->refresh();
        $release = Script::query()
            ->where('organization_id', $org->id)
            ->where('name', ScriptSeeder::NAME_RELEASE_CONTEXT)
            ->first();
        $this->assertNotNull($release);
        $this->assertSame((string) $release->id, (string) $org->default_site_script_id);
    }

    public function test_seeding_twice_does_not_duplicate_scripts(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);

        $this->seed(ScriptSeeder::class);
        $this->seed(ScriptSeeder::class);

        $this->assertSame(
            3,
            Script::query()->where('organization_id', $org->id)->count()
        );
    }
}
