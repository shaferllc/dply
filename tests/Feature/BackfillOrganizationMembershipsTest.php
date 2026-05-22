<?php

namespace Tests\Feature\BackfillOrganizationMembershipsTest;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

test('command creates a workspace for users without an organization', function () {
    $user = User::factory()->create([
        'name' => 'Orphaned User',
        'email' => 'orphaned@example.com',
    ]);

    expect($user->organizations()->exists())->toBeFalse();

    Artisan::call('dply:backfill-organizations');

    $org = Organization::query()->where('name', "Orphaned User's Workspace")->first();

    expect($org)->not->toBeNull();
    expect($org->hasMember($user->fresh()))->toBeTrue();
});
