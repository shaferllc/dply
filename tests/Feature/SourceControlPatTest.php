<?php

declare(strict_types=1);

namespace Tests\Feature\SourceControlPatTest;

use App\Livewire\Settings\SourceControl;
use App\Models\GitProviderToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('savePat validates the token against /user and stores it', function () {
    Http::fake([
        'https://api.github.com/user' => Http::response([
            'id' => 42,
            'login' => 'octocat',
        ], 200),
    ]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(SourceControl::class)
        ->call('startAddPat', 'github')
        ->set('patLabel', 'Work account')
        ->set('patToken', 'ghp_abcdefghijklmnop')
        ->call('savePat')
        ->assertHasNoErrors();

    $pat = GitProviderToken::query()->where('user_id', $user->id)->first();
    expect($pat)->not->toBeNull();
    expect($pat->provider)->toBe('github');
    expect($pat->provider_id)->toBe('42');
    expect($pat->nickname)->toBe('octocat');
    expect($pat->label)->toBe('Work account');
    expect($pat->access_token)->toBe('ghp_abcdefghijklmnop');
    expect($pat->last_validated_at)->not->toBeNull();
});

test('savePat surfaces an error when the provider rejects the token', function () {
    Http::fake([
        'https://api.github.com/user' => Http::response(['message' => 'Bad credentials'], 401),
    ]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(SourceControl::class)
        ->call('startAddPat', 'github')
        ->set('patToken', 'ghp_invalidtoken')
        ->call('savePat')
        ->assertHasErrors(['patToken']);

    expect(GitProviderToken::query()->where('user_id', $user->id)->count())->toBe(0);
});

test('savePat honours a custom api base URL for self-hosted GitLab', function () {
    Http::fake([
        'https://gitlab.acme.com/api/v4/user' => Http::response([
            'id' => 7,
            'username' => 'machine-user',
        ], 200),
        '*' => Http::response('unexpected host', 500),
    ]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(SourceControl::class)
        ->call('startAddPat', 'gitlab')
        ->set('patToken', 'glpat-self-hosted')
        ->set('patApiBaseUrl', 'https://gitlab.acme.com')
        ->call('savePat')
        ->assertHasNoErrors();

    $pat = GitProviderToken::query()->where('user_id', $user->id)->firstOrFail();
    expect($pat->api_base_url)->toBe('https://gitlab.acme.com');
    expect($pat->provider_id)->toBe('7');
});

test('saveEditPat updates the label without changing the token', function () {
    Http::fake();
    $user = User::factory()->create();
    $pat = GitProviderToken::create([
        'user_id' => $user->id,
        'provider' => 'github',
        'access_token' => 'ghp_original',
    ]);

    Livewire::actingAs($user)
        ->test(SourceControl::class)
        ->call('startEditPat', (string) $pat->id)
        ->set('editPatLabel', 'Renamed token')
        ->call('saveEditPat')
        ->assertHasNoErrors();

    $pat->refresh();
    expect($pat->label)->toBe('Renamed token');
    expect($pat->access_token)->toBe('ghp_original');
});

test('unlinkPat removes the token', function () {
    $user = User::factory()->create();
    $pat = GitProviderToken::create([
        'user_id' => $user->id,
        'provider' => 'github',
        'access_token' => 'ghp_doomed',
    ]);

    Livewire::actingAs($user)
        ->test(SourceControl::class)
        ->call('unlinkPat', (string) $pat->id);

    expect(GitProviderToken::query()->find($pat->id))->toBeNull();
});
