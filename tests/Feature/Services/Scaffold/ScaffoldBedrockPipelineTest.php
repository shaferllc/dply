<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Scaffold\ScaffoldBedrockPipelineTest;

use App\Models\Server;
use App\Models\Site;
use App\Modules\RemoteCli\Services\SiteAuditWriter;
use App\Modules\Scaffold\Services\PlaceholderDnsManager;
use App\Modules\Scaffold\Services\PrerequisiteResult;
use App\Modules\Scaffold\Services\ScaffoldComposerPipeline;
use App\Modules\Scaffold\Services\ScaffoldPrerequisites;
use App\Modules\Scaffold\Services\ScaffoldRepoSeeder;
use App\Modules\TaskRunner\ProcessOutput;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Servers\ServerDatabaseProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

uses(RefreshDatabase::class);

afterEach(function () {
    Mockery::close();
});

function bedrockDns(string $hostname = 'my-bedrock-site.198-51-100-9.nip.io'): PlaceholderDnsManager
{
    $mock = Mockery::mock(PlaceholderDnsManager::class);
    $mock->shouldReceive('assign')->andReturn([
        'hostname' => $hostname,
        'zone' => null,
        'record_id' => null,
        'source' => 'nip.io',
    ]);
    $mock->shouldReceive('release')->andReturnNull();

    return $mock;
}

function bedrockSite(): Site
{
    $server = Server::factory()->ready()->create([
        'meta' => [
            'webserver' => 'nginx',
            'php_version' => '8.3',
            'installed_stack' => ['database' => 'mariadb1011'],
        ],
    ]);

    return Site::factory()->for($server)->create([
        'slug' => 'my-bedrock-site',
        'status' => Site::STATUS_SCAFFOLDING,
        'meta' => [
            'scaffold' => [
                'framework' => 'wordpress',
                'layout' => 'bedrock',
                'admin_email' => 'admin@example.com',
                'recipe' => [
                    'package' => 'roots/bedrock',
                    'needs_db' => true,
                    'env' => 'bedrock',
                    'migrate' => false,
                    'wp_install' => true,
                ],
            ],
        ],
    ]);
}

/**
 * Captures every inline bash command the pipeline emits so the Bedrock-specific
 * ones can be asserted on, and returns non-empty output so the theme step's
 * active-theme verification passes.
 *
 * @param  array<int, string>  $commands
 */
function bedrockPipeline(array &$commands, ?PlaceholderDnsManager $dns = null): ScaffoldComposerPipeline
{
    $prereqs = Mockery::mock(ScaffoldPrerequisites::class);
    $prereqs->shouldReceive('ensureComposer')->andReturn(PrerequisiteResult::alreadyPresent('composer'));
    $prereqs->shouldReceive('ensureWpCli')->andReturn(PrerequisiteResult::alreadyPresent('wp-cli'));

    $dbProvisioner = Mockery::mock(ServerDatabaseProvisioner::class);
    $dbProvisioner->shouldReceive('createOnServer')->andReturn('ok');

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBash')
        ->andReturnUsing(function (...$args) use (&$commands) {
            $commands[] = (string) ($args[2] ?? '');

            return new ProcessOutput('twentytwentyfive', 0, false);
        });

    $seeder = Mockery::mock(ScaffoldRepoSeeder::class);
    $seeder->shouldIgnoreMissing();

    return new ScaffoldComposerPipeline(
        $prereqs,
        $dbProvisioner,
        $executor,
        app(SiteAuditWriter::class),
        $dns ?? bedrockDns(),
        $seeder,
    );
}

test('bedrock installs wordpress rather than leaving the installer screen', function () {
    $commands = [];
    $site = bedrockSite();

    $result = bedrockPipeline($commands)->run($site);

    expect($result['ok'])->toBeTrue();

    $all = implode("\n", $commands);

    // composer create-project only lays down files; without wp core install the
    // site renders WordPress's own installer instead of a working site.
    expect($all)->toContain('composer create-project')
        ->and($all)->toContain('roots/bedrock')
        ->and($all)->toContain('wp core install')
        // Core lives under web/wp in a Bedrock tree.
        ->and($all)->toContain('--path=web/wp');
});

test('bedrock writes its own env schema, not laravels', function () {
    $commands = [];
    $result = bedrockPipeline($commands)->run(bedrockSite());

    expect($result['ok'])->toBeTrue();
    $all = implode("\n", $commands);

    // Bedrock reads DB_NAME/DB_USER/DB_PASSWORD and WP_HOME/WP_SITEURL.
    expect($all)->toContain('WP_HOME=')
        ->and($all)->toContain('WP_SITEURL=')
        ->and($all)->toContain('DB_NAME=')
        ->and($all)->toContain('DB_USER=')
        // Laravel's key:generate has no meaning in a Bedrock tree.
        ->and($all)->not->toContain('artisan key:generate');
});

test('bedrock writes all eight wordpress salts', function () {
    $commands = [];
    bedrockPipeline($commands)->run(bedrockSite());
    $all = implode("\n", $commands);

    foreach ([
        'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY',
        'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT',
    ] as $salt) {
        expect($all)->toContain($salt);
    }
});

test('bedrock installs a theme too', function () {
    $commands = [];
    $site = bedrockSite();
    bedrockPipeline($commands)->run($site);

    // Bedrock's web/app/themes is empty after create-project, so the same
    // no-active-theme fatal applies as in the classic pipeline.
    expect(implode("\n", $commands))->toContain('wp theme install');

    $site->refresh();
    expect($site->meta['scaffold']['theme'])->toBe('twentytwentyfive');
});

test('bedrock records an encrypted admin password', function () {
    $commands = [];
    $site = bedrockSite();
    bedrockPipeline($commands)->run($site);

    $site->refresh();
    $stored = $site->meta['scaffold']['admin_password'] ?? null;

    expect($stored)->not->toBeNull()
        ->and(decrypt($stored))->toBeString()
        // Never persisted in the clear.
        ->and($stored)->not->toContain('admin');
});

test('the journey lists the bedrock steps', function () {
    $commands = [];
    $site = bedrockSite();
    bedrockPipeline($commands)->run($site);

    $site->refresh();
    $keys = collect($site->meta['scaffold']['steps'])->pluck('key')->all();

    expect($keys)->toContain('composer_create')
        ->and($keys)->toContain('write_env')
        ->and($keys)->toContain('wp_install')
        ->and($keys)->toContain('wp_theme')
        // Bedrock has no Laravel migrations to run.
        ->and($keys)->not->toContain('migrate');
});

test('bedrock is treated as code-first and gets a seeded repo', function () {
    $site = bedrockSite();

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $script = null;
    $executor->shouldReceive('runInlineBash')->andReturnUsing(function (...$args) use (&$script) {
        $script = (string) ($args[2] ?? '');

        return new ProcessOutput('', 0, false);
    });

    $seeded = (new ScaffoldRepoSeeder($executor))->seed($site);

    // Bedrock is framework=wordpress but a Composer project, so composer.json
    // is the source of truth and belongs in git from the first commit.
    expect($seeded)->toBeTrue()
        ->and($script)->toContain('git init')
        ->and($script)->toContain('Initial Bedrock scaffold');

    $site->refresh();
    expect($site->git_repository_url)->not->toBeNull()
        ->and($site->git_branch)->toBe('main');
});

test('classic wordpress stays manage-in-place with no repo', function () {
    $site = bedrockSite();
    $meta = $site->meta;
    $meta['scaffold']['layout'] = 'classic';
    $site->forceFill(['meta' => $meta])->save();

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldNotReceive('runInlineBash');

    expect((new ScaffoldRepoSeeder($executor))->seed($site->fresh()))->toBeFalse();

    expect($site->fresh()->git_repository_url)->toBeNull();
});
