<?php

declare(strict_types=1);

namespace Tests\Unit\Services\SourceControlRepositoryBrowserTest;

use App\Models\GitProviderToken;
use App\Models\SocialAccount;
use App\Services\SourceControl\SourceControlRepositoryBrowser;
use Illuminate\Support\Facades\Http;

test('it lists github repositories for a linked account', function () {
    Http::fake([
        'https://api.github.com/user/repos*' => Http::response([
            [
                'full_name' => 'acme/hello-functions',
                'clone_url' => 'https://github.com/acme/hello-functions.git',
                'default_branch' => 'main',
            ],
        ], 200),
    ]);

    $browser = new SourceControlRepositoryBrowser;
    $account = new SocialAccount([
        'provider' => 'github',
        'access_token' => 'gho_test',
    ]);

    $repositories = $browser->repositoriesForAccount($account);

    expect($repositories)->toHaveCount(1);
    expect($repositories[0]['label'])->toBe('acme/hello-functions');
    expect($repositories[0]['url'])->toBe('https://github.com/acme/hello-functions.git');
    expect($repositories[0]['branch'])->toBe('main');
});
test('it builds an authenticated clone url for github', function () {
    $browser = new SourceControlRepositoryBrowser;
    $account = new SocialAccount([
        'provider' => 'github',
        'access_token' => 'gho_test',
    ]);

    $url = $browser->authenticatedCloneUrl($account, 'https://github.com/acme/hello-functions.git');

    expect($url)->toBe('https://x-access-token:gho_test@github.com/acme/hello-functions.git');
});

test('it lists github repositories using a personal access token', function () {
    Http::fake([
        'https://api.github.com/user/repos*' => Http::response([
            [
                'full_name' => 'acme/secret-service',
                'clone_url' => 'https://github.com/acme/secret-service.git',
                'default_branch' => 'main',
            ],
        ], 200),
    ]);

    $browser = new SourceControlRepositoryBrowser;
    $pat = new GitProviderToken([
        'provider' => 'github',
        'access_token' => 'ghp_personal',
    ]);

    $repositories = $browser->repositoriesForAccount($pat);

    expect($repositories)->toHaveCount(1);
    expect($repositories[0]['url'])->toBe('https://github.com/acme/secret-service.git');
});

test('it routes gitlab API calls to the PAT base URL for self-hosted hosts', function () {
    Http::fake([
        'https://gitlab.acme.com/api/v4/projects*' => Http::response([], 200),
        '*' => Http::response('unexpected host', 500),
    ]);

    $browser = new SourceControlRepositoryBrowser;
    $pat = new GitProviderToken([
        'provider' => 'gitlab',
        'access_token' => 'glpat-secret',
        'api_base_url' => 'https://gitlab.acme.com',
    ]);

    $browser->repositoriesForAccount($pat);

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://gitlab.acme.com/api/v4/projects'));
});
