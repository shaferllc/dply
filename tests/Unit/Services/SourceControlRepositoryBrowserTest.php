<?php

declare(strict_types=1);

namespace Tests\Unit\Services\SourceControlRepositoryBrowserTest;
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
