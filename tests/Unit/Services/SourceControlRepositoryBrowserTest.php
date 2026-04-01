<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\SocialAccount;
use App\Services\SourceControl\SourceControlRepositoryBrowser;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SourceControlRepositoryBrowserTest extends TestCase
{
    public function test_it_lists_github_repositories_for_a_linked_account(): void
    {
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

        $this->assertCount(1, $repositories);
        $this->assertSame('acme/hello-functions', $repositories[0]['label']);
        $this->assertSame('https://github.com/acme/hello-functions.git', $repositories[0]['url']);
        $this->assertSame('main', $repositories[0]['branch']);
    }

    public function test_it_builds_an_authenticated_clone_url_for_github(): void
    {
        $browser = new SourceControlRepositoryBrowser;
        $account = new SocialAccount([
            'provider' => 'github',
            'access_token' => 'gho_test',
        ]);

        $url = $browser->authenticatedCloneUrl($account, 'https://github.com/acme/hello-functions.git');

        $this->assertSame(
            'https://x-access-token:gho_test@github.com/acme/hello-functions.git',
            $url
        );
    }
}
