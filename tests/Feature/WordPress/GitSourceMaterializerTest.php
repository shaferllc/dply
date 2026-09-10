<?php

declare(strict_types=1);

namespace Tests\Feature\WordPress\GitSourceMaterializerTest;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteGitSource;
use App\Models\User;
use App\Modules\SourceControl\Contracts\GitIdentity;
use App\Modules\SourceControl\Services\GitIdentityResolver;
use App\Modules\SourceControl\Services\SourceControlRepositoryBrowser;
use App\Modules\TaskRunner\ProcessOutput;
use App\Modules\WordPress\Materializers\BedrockGitSourceMaterializer;
use App\Modules\WordPress\Materializers\ClassicGitSourceMaterializer;
use App\Modules\WordPress\Materializers\GitSourceMaterializerFactory;
use App\Modules\WordPress\Services\GitSourceCredentials;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

uses(RefreshDatabase::class);

afterEach(function () {
    Mockery::close();
});

function wpSite(string $layout = 'classic', string $framework = 'wordpress'): Site
{
    $server = Server::factory()->ready()->create([
        'meta' => ['webserver' => 'nginx', 'php_version' => '8.3'],
    ]);

    return Site::factory()->for($server)->create([
        'slug' => 'my-wp',
        'meta' => ['scaffold' => ['framework' => $framework, 'layout' => $layout]],
    ]);
}

/** @param array<string, mixed> $attrs */
function source(Site $site, string $kind = SiteGitSource::KIND_THEME, array $attrs = []): SiteGitSource
{
    return $site->gitSources()->create(array_merge([
        'kind' => $kind,
        'slug' => 'my-theme',
        'repository_url' => 'git@github.com:acme/my-theme.git',
        'git_branch' => 'main',
        'status' => SiteGitSource::STATUS_PENDING,
    ], $attrs));
}

/** @param array<int, string> $commands */
function recordingExecutor(array &$commands, string $stdout = 'abc123', int $exit = 0): ExecuteRemoteTaskOnServer
{
    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBash')->andReturnUsing(
        function (...$args) use (&$commands, $stdout, $exit) {
            $commands[] = (string) ($args[2] ?? '');

            return new ProcessOutput($stdout, $exit, false);
        }
    );

    return $executor;
}

test('classic clones the repo into wp-content at the slug directory', function () {
    $commands = [];
    $src = source(wpSite('classic'));

    (new ClassicGitSourceMaterializer(recordingExecutor($commands), app(GitSourceCredentials::class)))->sync($src);

    $all = implode("\n", $commands);

    expect($all)->toContain('/home/dply/my-wp/current/wp-content/themes/my-theme')
        ->and($all)->toContain('git clone')
        // Reset, not pull: a merge conflict on a live box is worse than
        // discarding local churn, and the repo owns this path.
        ->and($all)->toContain('git reset --hard FETCH_HEAD')
        ->and($all)->not->toContain('composer require');

    $src->refresh();
    expect($src->status)->toBe(SiteGitSource::STATUS_SYNCED)
        ->and($src->last_synced_commit)->toBe('abc123');
});

test('classic puts plugins under plugins, not themes', function () {
    $commands = [];
    $src = source(wpSite('classic'), SiteGitSource::KIND_PLUGIN, ['slug' => 'my-plugin']);

    (new ClassicGitSourceMaterializer(recordingExecutor($commands), app(GitSourceCredentials::class)))->sync($src);

    expect(implode("\n", $commands))->toContain('wp-content/plugins/my-plugin');
});

test('classic passes a per-source deploy key and removes it afterwards', function () {
    $commands = [];
    $src = source(wpSite('classic'));

    (new ClassicGitSourceMaterializer(recordingExecutor($commands), app(GitSourceCredentials::class)))->sync($src);

    $all = implode("\n", $commands);

    // Per-source rather than the site's own key, so revoking one private theme
    // repo cannot break every other source on the site.
    expect($all)->toContain('dply-git-source-'.$src->id)
        ->and($all)->toContain('GIT_SSH_COMMAND')
        ->and($all)->toContain('IdentitiesOnly=yes')
        // A deploy key left on disk is a standing credential.
        ->and($all)->toContain('rm -f');

    $src->refresh();
    expect($src->deploy_key_public)->not->toBeNull();
});

test('classic refuses to remove a path outside wp-content', function () {
    $commands = [];
    $src = source(wpSite('classic'));

    // A blank slug would otherwise resolve to the wp-content directory itself
    // and hand a recursive delete the whole content tree.
    $src->forceFill(['slug' => ''])->save();

    expect(fn () => (new ClassicGitSourceMaterializer(recordingExecutor($commands), app(GitSourceCredentials::class)))->remove($src))
        ->toThrow(\RuntimeException::class);

    expect($commands)->toBeEmpty();
});

test('bedrock adds a composer vcs repository and requires the package', function () {
    $commands = [];
    $src = source(wpSite('bedrock'));

    (new BedrockGitSourceMaterializer(recordingExecutor($commands), app(GitSourceCredentials::class)))->sync($src);

    $all = implode("\n", $commands);

    expect($all)->toContain('composer config repositories.')
        ->and($all)->toContain('vcs')
        ->and($all)->toContain('composer require')
        // Derived from the repo URL when no explicit package is set.
        ->and($all)->toContain('acme/my-theme')
        ->and($all)->toContain('dev-main')
        // Composer owns web/app/themes via composer/installers; cloning into it
        // would produce a tree composer fights with on the next update.
        ->and($all)->not->toContain('git clone');
});

test('bedrock scopes the update so adding a theme cannot bump wordpress core', function () {
    $commands = [];

    (new BedrockGitSourceMaterializer(recordingExecutor($commands), app(GitSourceCredentials::class)))->sync(source(wpSite('bedrock')));

    expect(implode("\n", $commands))->toContain('--update-with-dependencies');
});

test('bedrock prefers an explicit composer package over the url guess', function () {
    $commands = [];
    $src = source(wpSite('bedrock'), SiteGitSource::KIND_PLUGIN, [
        'slug' => 'my-plugin',
        'composer_package' => 'vendor/custom-name',
    ]);

    (new BedrockGitSourceMaterializer(recordingExecutor($commands), app(GitSourceCredentials::class)))->sync($src);

    expect(implode("\n", $commands))->toContain('vendor/custom-name');
});

test('bedrock removal drops the repository entry too, not just the require', function () {
    $commands = [];
    $src = source(wpSite('bedrock'));

    (new BedrockGitSourceMaterializer(recordingExecutor($commands), app(GitSourceCredentials::class)))->remove($src);

    $all = implode("\n", $commands);

    // An orphan vcs repository means every later composer command still tries
    // to reach a repo the site no longer uses.
    expect($all)->toContain('composer remove')
        ->and($all)->toContain('composer config --unset repositories.');
});

test('a failed sync surfaces the reason rather than reporting success', function () {
    $commands = [];
    $src = source(wpSite('classic'));

    $executor = recordingExecutor($commands, 'Permission denied (publickey)', 1);

    expect(fn () => (new ClassicGitSourceMaterializer($executor, app(GitSourceCredentials::class)))->sync($src))
        ->toThrow(\RuntimeException::class);
});

test('the factory picks a materializer by layout and defaults to classic', function () {
    $factory = app(GitSourceMaterializerFactory::class);

    expect($factory->for(wpSite('bedrock')))->toBeInstanceOf(BedrockGitSourceMaterializer::class)
        ->and($factory->for(wpSite('classic')))->toBeInstanceOf(ClassicGitSourceMaterializer::class);

    // Sites scaffolded before the layout key existed are all classic
    // wp-core-download installs; treating one as Bedrock would run composer
    // against a tree with no composer.json.
    $legacy = wpSite('');
    expect($factory->layoutOf($legacy))->toBe('classic')
        ->and($factory->for($legacy))->toBeInstanceOf(ClassicGitSourceMaterializer::class);
});

test('the factory only supports wordpress sites', function () {
    $factory = app(GitSourceMaterializerFactory::class);

    expect($factory->supports(wpSite('classic')))->toBeTrue()
        // Cloning a WordPress theme into a Laravel site would target a
        // wp-content directory that does not exist.
        ->and($factory->supports(wpSite('classic', 'laravel')))->toBeFalse();
});

/**
 * Credentials that resolve a connected account to a token, without touching a
 * real provider.
 */
function connectedCredentials(string $authenticatedUrl): GitSourceCredentials
{
    $identity = Mockery::mock(GitIdentity::class);

    $resolver = Mockery::mock(GitIdentityResolver::class);
    $resolver->shouldReceive('forId')->andReturn($identity);

    $browser = Mockery::mock(SourceControlRepositoryBrowser::class);
    $browser->shouldReceive('authenticatedCloneUrl')->andReturn($authenticatedUrl);

    return new GitSourceCredentials($resolver, $browser);
}

/**
 * A repo picked through a connected account authenticates through the
 * environment and leaves no credential behind on the box: not in the clone URL
 * (argv, visible to every user in `ps`) and not as `origin` in .git/config.
 */
test('classic clones a connected repo without persisting the token or a deploy key', function () {
    $user = User::factory()->create();
    $src = source(wpSite(), SiteGitSource::KIND_THEME, [
        'repository_url' => 'git@github.com:acme/my-theme.git',
        'source_control_account_id' => 'acct-1',
        'connected_by_user_id' => $user->id,
    ]);
    $commands = [];

    (new ClassicGitSourceMaterializer(
        recordingExecutor($commands),
        connectedCredentials('https://x-access-token:s3cret@github.com/acme/my-theme.git'),
    ))->sync($src);

    $all = implode("\n", $commands);

    expect($all)->toContain('GIT_CONFIG_COUNT')
        ->and($all)->toContain("git remote set-url origin 'https://github.com/acme/my-theme.git'")
        ->and($all)->not->toContain('s3cret@')
        ->and($all)->not->toContain('x-access-token:s3cret')
        ->and($all)->not->toContain('dply-git-source-'.$src->id)
        ->and($all)->not->toContain('GIT_SSH_COMMAND');
});

/**
 * Composer reads the token from COMPOSER_AUTH for one command. `composer config
 * http-basic` would write it into auth.json on the box; no-api makes Composer
 * clone through git, which honours http-basic, instead of calling the provider
 * API with the wrong credential type.
 */
test('bedrock authenticates a connected repo through COMPOSER_AUTH, never auth.json', function () {
    $user = User::factory()->create();
    $src = source(wpSite('bedrock'), SiteGitSource::KIND_THEME, [
        'repository_url' => 'https://github.com/acme/my-theme.git',
        'source_control_account_id' => 'acct-1',
        'connected_by_user_id' => $user->id,
    ]);
    $commands = [];

    (new BedrockGitSourceMaterializer(
        recordingExecutor($commands),
        connectedCredentials('https://x-access-token:s3cret@github.com/acme/my-theme.git'),
    ))->sync($src);

    $all = implode("\n", $commands);

    expect($all)->toContain('export COMPOSER_AUTH=')
        ->and($all)->toContain('"no-api":true')
        ->and($all)->toContain('"url":"https://github.com/acme/my-theme.git"')
        ->and($all)->not->toContain('composer config http-basic')
        ->and($all)->not->toContain('GIT_SSH_COMMAND');
});
