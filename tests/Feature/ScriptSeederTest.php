<?php

namespace Tests\Feature\ScriptSeederTest;

use App\Models\Organization;
use App\Models\Script;
use App\Models\User;
use Database\Seeders\ScriptSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('seeds default scripts for seed users organization', function () {
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
    expect($release)->not->toBeNull();
    expect((string) $org->default_site_script_id)->toBe((string) $release->id);
});

test('seeding twice does not duplicate scripts', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $this->seed(ScriptSeeder::class);
    $this->seed(ScriptSeeder::class);

    expect(Script::query()->where('organization_id', $org->id)->count())->toBe(3);
});
