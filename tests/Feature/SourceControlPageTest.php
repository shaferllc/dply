<?php

namespace Tests\Feature\SourceControlPageTest;

use App\Models\GitProviderToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the page renders a provider card once something is linked', function () {
    $user = User::factory()->create();

    // A provider only appears once OAuth is configured or a token exists. Without
    // one, every layout renders its empty state and the provider markup — where
    // the last two syntax errors lived — is never exercised.
    GitProviderToken::query()->create([
        'user_id' => $user->id,
        'provider' => 'github',
        'nickname' => 'preview-token',
        'access_token' => 'ghp_exampleexampleexample',
    ]);

    $this->actingAs($user)
        ->get(route('profile.source-control'))
        ->assertOk()
        ->assertSee('Source control')
        ->assertSee('preview-token')
        ->assertSee('GitHub');
});

test('the page renders with no providers linked', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('profile.source-control'))
        ->assertOk()
        ->assertSee('Source control');
});
