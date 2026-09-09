<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Scaffold\ScaffoldWordPressPipelineTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\Site;
use App\Models\SiteAuditEvent;
use App\Models\User;
use App\Modules\RemoteCli\Services\SiteAuditWriter;
use App\Modules\Scaffold\Services\PlaceholderDnsManager;
use App\Modules\Scaffold\Services\PrerequisiteResult;
use App\Modules\Scaffold\Services\ScaffoldPrerequisites;
use App\Modules\Scaffold\Services\ScaffoldStep;
use App\Modules\Scaffold\Services\ScaffoldWordPressPipeline;
use App\Modules\TaskRunner\ProcessOutput;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Servers\ServerDatabaseProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

uses(RefreshDatabase::class);

afterEach(function () {
    Mockery::close();
});
function placeholderDnsAlwaysAssigns(string $hostname = 'my-wp-blog.198-51-100-3.nip.io'): PlaceholderDnsManager
{
    $mock = Mockery::mock(PlaceholderDnsManager::class);
    $mock->shouldReceive('assign')
        ->andReturn([
            'hostname' => $hostname,
            'zone' => null,
            'record_id' => null,
            'source' => 'nip.io',
        ]);
    $mock->shouldReceive('release')->andReturnNull();

    return $mock;
}
function makeScaffoldingSite(string $serverEngine = 'mariadb114'): Site
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['database' => $serverEngine],
    ]);

    return Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'My WP Blog',
        'slug' => 'my-wp-blog',
        'status' => Site::STATUS_SCAFFOLDING,
        'meta' => [
            'scaffold' => [
                'framework' => 'wordpress',
                'admin_email' => 'admin@example.com',
            ],
        ],
    ]);
}
test('happy path walks every step', function () {
    $site = makeScaffoldingSite();

    $prereqs = Mockery::mock(ScaffoldPrerequisites::class);
    $prereqs->shouldReceive('ensureWpCli')->once()->andReturn(PrerequisiteResult::alreadyPresent('wp-cli'));

    $dbProvisioner = Mockery::mock(ServerDatabaseProvisioner::class);
    $dbProvisioner->shouldReceive('createOnServer')->once()->andReturn('ok');

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBash')->andReturn(new ProcessOutput('ok', 0, false));

    $audit = app(SiteAuditWriter::class);

    $result = (new ScaffoldWordPressPipeline($prereqs, $dbProvisioner, $executor, $audit, placeholderDnsAlwaysAssigns()))->run($site);

    expect($result['ok'])->toBeTrue();

    $site->refresh();
    expect($site->status)->toBe(Site::STATUS_PENDING);

    $steps = collect($site->meta['scaffold']['steps']);

    // 8 = original 6 + placeholder_dns + wp_theme
    expect($steps)->toHaveCount(8);
    expect($steps->every(fn ($s) => $s['state'] === ScaffoldStep::STATE_COMPLETED))->toBeTrue();

    // Q18 hardening opinions recorded for the Hardening tab to read.
    expect($site->meta['scaffold']['hardening'])->not->toBeEmpty();
    expect($site->meta['scaffold']['hardening'])->toHaveCount(6);

    $db = ServerDatabase::query()->sole();
    expect($db->engine)->toBe('mariadb114');

    // Each opinion writes its own audit row.
    $opinionAudits = SiteAuditEvent::query()->where('action', 'scaffold_default_applied')->get();
    expect($opinionAudits)->toHaveCount(6);

    $completed = SiteAuditEvent::query()->where('action', 'scaffold_completed')->sole();
    expect($completed->payload['framework'])->toBe('wordpress');
});
test('falls back to mysql when server engine is postgres', function () {
    // Defensive: even though the wizard should disable the WordPress
    // tile on Postgres-only hosts, the pipeline guards the engine
    // pick at runtime.
    $site = makeScaffoldingSite(serverEngine: 'postgres17');

    $prereqs = Mockery::mock(ScaffoldPrerequisites::class);
    $prereqs->shouldReceive('ensureWpCli')->andReturn(PrerequisiteResult::alreadyPresent('wp-cli'));

    $dbProvisioner = Mockery::mock(ServerDatabaseProvisioner::class);
    $dbProvisioner->shouldReceive('createOnServer')->andReturn('ok');

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBash')->andReturn(new ProcessOutput('ok', 0, false));

    (new ScaffoldWordPressPipeline($prereqs, $dbProvisioner, $executor, app(SiteAuditWriter::class), placeholderDnsAlwaysAssigns()))->run($site);

    $db = ServerDatabase::query()->sole();
    expect($db->engine)->toBe('mysql84', 'Pipeline must fall back to mysql84 when server engine is incompatible');
});
test('failed wp install marks failed and audits', function () {
    $site = makeScaffoldingSite();

    $prereqs = Mockery::mock(ScaffoldPrerequisites::class);
    $prereqs->shouldReceive('ensureWpCli')->andReturn(PrerequisiteResult::alreadyPresent('wp-cli'));

    $dbProvisioner = Mockery::mock(ServerDatabaseProvisioner::class);
    $dbProvisioner->shouldReceive('createOnServer')->andReturn('ok');

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);

    // First three runs succeed (download, config, install setup),
    // wp_install (4th call) fails.
    $executor->shouldReceive('runInlineBash')
        ->withArgs(fn ($s, string $name) => $name !== 'scaffold-wp:core-install')
        ->andReturn(new ProcessOutput('ok', 0, false));
    $executor->shouldReceive('runInlineBash')
        ->withArgs(fn ($s, string $name) => $name === 'scaffold-wp:core-install')
        ->andReturn(new ProcessOutput('Error: Could not connect to db', 1, false));

    $result = (new ScaffoldWordPressPipeline($prereqs, $dbProvisioner, $executor, app(SiteAuditWriter::class), placeholderDnsAlwaysAssigns()))->run($site);

    expect($result['ok'])->toBeFalse();
    expect($result['failed_step'])->toBe('wp_install');

    $site->refresh();
    expect($site->status)->toBe(Site::STATUS_SCAFFOLD_FAILED);

    // apply_hardening must NOT have run.
    $hardening = collect($site->meta['scaffold']['steps'])->firstWhere('key', 'apply_hardening');
    expect($hardening['state'])->toBe(ScaffoldStep::STATE_PENDING);
});
test('wp install uses placeholder hostname in url argument', function () {
    // Critical Q12 invariant: wp core install bakes the URL into
    // wp_options.siteurl + wp_options.home in serialized form, so
    // the placeholder hostname must be passed to --url= on the
    // first install rather than rewritten later via search-replace.
    $site = makeScaffoldingSite();

    $prereqs = Mockery::mock(ScaffoldPrerequisites::class);
    $prereqs->shouldReceive('ensureWpCli')->andReturn(PrerequisiteResult::alreadyPresent('wp-cli'));
    $dbProvisioner = Mockery::mock(ServerDatabaseProvisioner::class);
    $dbProvisioner->shouldReceive('createOnServer')->andReturn('ok');

    $installBash = null;
    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBash')
        ->withArgs(function ($s, string $name, string $bash) use (&$installBash) {
            if ($name === 'scaffold-wp:core-install') {
                $installBash = $bash;
            }

            return true;
        })
        ->andReturn(new ProcessOutput('Success: WordPress installed.', 0, false));

    $dns = placeholderDnsAlwaysAssigns(hostname: 'my-wp-blog.203-0-113-42.nip.io');

    (new ScaffoldWordPressPipeline($prereqs, $dbProvisioner, $executor, app(SiteAuditWriter::class), $dns))
        ->run($site);

    expect($installBash)->not->toBeNull('wp core install step should have run');
    $this->assertStringContainsString("--url='http://my-wp-blog.203-0-113-42.nip.io'", $installBash);
});

test('the scaffold installs and activates a theme', function () {
    config(['sites.wordpress_default_theme' => 'twentytwentyfive']);
    $site = makeScaffoldingSite();

    $prereqs = Mockery::mock(ScaffoldPrerequisites::class);
    $prereqs->shouldReceive('ensureWpCli')->once()->andReturn(PrerequisiteResult::alreadyPresent('wp-cli'));

    $dbProvisioner = Mockery::mock(ServerDatabaseProvisioner::class);
    $dbProvisioner->shouldReceive('createOnServer')->once()->andReturn('ok');

    // `wp core download --skip-content` ships no themes, so the scaffold has to
    // put one back or the site fatals on its first front-end request.
    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBash')->andReturn(new ProcessOutput('twentytwentyfive', 0, false));

    $result = (new ScaffoldWordPressPipeline($prereqs, $dbProvisioner, $executor, app(SiteAuditWriter::class), placeholderDnsAlwaysAssigns()))->run($site);

    expect($result['ok'])->toBeTrue();

    $site->refresh();
    expect($site->meta['scaffold']['theme'])->toBe('twentytwentyfive');

    $steps = collect($site->meta['scaffold']['steps']);
    expect($steps->firstWhere('key', 'wp_theme'))->not->toBeNull();
});

test('the scaffold fails loudly when no theme ends up active', function () {
    $site = makeScaffoldingSite();

    $prereqs = Mockery::mock(ScaffoldPrerequisites::class);
    $prereqs->shouldReceive('ensureWpCli')->once()->andReturn(PrerequisiteResult::alreadyPresent('wp-cli'));

    $dbProvisioner = Mockery::mock(ServerDatabaseProvisioner::class);
    $dbProvisioner->shouldReceive('createOnServer')->once()->andReturn('ok');

    // wp theme install can exit 0 having only warned, so the step verifies an
    // active theme rather than trusting the exit code. Empty output = none.
    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBash')->andReturn(new ProcessOutput('', 0, false));

    $result = (new ScaffoldWordPressPipeline($prereqs, $dbProvisioner, $executor, app(SiteAuditWriter::class), placeholderDnsAlwaysAssigns()))->run($site);

    expect($result['ok'])->toBeFalse();
    expect($result['failed_step'])->toBe('wp_theme');

    $site->refresh();
    expect($site->status)->toBe(Site::STATUS_SCAFFOLD_FAILED);
});

test('wp config create pipes extra php instead of using a shell heredoc', function () {
    $site = makeScaffoldingSite();

    $prereqs = Mockery::mock(ScaffoldPrerequisites::class);
    $prereqs->shouldReceive('ensureWpCli')->once()->andReturn(PrerequisiteResult::alreadyPresent('wp-cli'));

    $dbProvisioner = Mockery::mock(ServerDatabaseProvisioner::class);
    $dbProvisioner->shouldReceive('createOnServer')->once()->andReturn('ok');

    $commands = [];
    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBash')->andReturnUsing(function (...$args) use (&$commands) {
        $commands[] = (string) ($args[2] ?? '');

        return new ProcessOutput('twentytwentyfive', 0, false);
    });

    (new ScaffoldWordPressPipeline($prereqs, $dbProvisioner, $executor, app(SiteAuditWriter::class), placeholderDnsAlwaysAssigns()))->run($site);

    $config = collect($commands)->first(fn (string $c) => str_contains($c, 'wp config create'));

    expect($config)->not->toBeNull();

    // The bug: `--extra-php <<EOF` with an indented terminator. An unquoted
    // heredoc terminator must be at column 0, so bash never closed it and the
    // literal "EOF" was written into wp-config.php, right before wp-config's
    // own `if ( ! defined( 'ABSPATH' ) )` — a parse error that then broke every
    // wp-cli call, because wp-cli evals wp-config.php.
    expect($config)->not->toContain('<<EOF')
        ->and($config)->toContain('--extra-php')
        // Piped via STDIN, which cannot be broken by indentation.
        ->and($config)->toContain('| wp config create')
        ->and($config)->toContain('DISALLOW_FILE_EDIT');

    // Any heredoc that IS emitted must have its terminator at column 0.
    foreach ($commands as $command) {
        if (preg_match('/<<-?\'?([A-Z]+)\'?/', $command, $m) === 1) {
            expect($command)->toMatch('/^'.$m[1].'$/m');
        }
    }
});

test('wordpress is installed where the webserver actually serves', function () {
    $site = makeScaffoldingSite();
    $site->forceFill([
        'repository_path' => '/home/dply/my-wp-blog',
        'document_root' => '/home/dply/my-wp-blog',
    ])->save();

    $prereqs = Mockery::mock(ScaffoldPrerequisites::class);
    $prereqs->shouldReceive('ensureWpCli')->once()->andReturn(PrerequisiteResult::alreadyPresent('wp-cli'));

    $dbProvisioner = Mockery::mock(ServerDatabaseProvisioner::class);
    $dbProvisioner->shouldReceive('createOnServer')->once()->andReturn('ok');

    $commands = [];
    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBash')->andReturnUsing(function (...$args) use (&$commands) {
        $commands[] = (string) ($args[2] ?? '');

        return new ProcessOutput('twentytwentyfive', 0, false);
    });

    (new ScaffoldWordPressPipeline($prereqs, $dbProvisioner, $executor, app(SiteAuditWriter::class), placeholderDnsAlwaysAssigns()))->run($site->fresh());

    $all = implode("\n", $commands);

    // The pipeline used to write into '<path>/current' — the atomic-release
    // convention, which a manage-in-place install does not use. nginx served
    // '<path>' (document_root = repository_path + an empty web_subdir), so the
    // site showed only the splash page, and WpCli's `wp --path=<document_root>`
    // ran against a directory containing no WordPress.
    expect($all)->toContain("'/home/dply/my-wp-blog'")
        ->and($all)->not->toContain('/home/dply/my-wp-blog/current');
});

test('the install path falls back to the conventional one when unset', function () {
    $site = makeScaffoldingSite();
    $site->forceFill(['repository_path' => null, 'document_root' => null])->save();

    $prereqs = Mockery::mock(ScaffoldPrerequisites::class);
    $prereqs->shouldReceive('ensureWpCli')->once()->andReturn(PrerequisiteResult::alreadyPresent('wp-cli'));

    $dbProvisioner = Mockery::mock(ServerDatabaseProvisioner::class);
    $dbProvisioner->shouldReceive('createOnServer')->once()->andReturn('ok');

    $commands = [];
    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBash')->andReturnUsing(function (...$args) use (&$commands) {
        $commands[] = (string) ($args[2] ?? '');

        return new ProcessOutput('twentytwentyfive', 0, false);
    });

    (new ScaffoldWordPressPipeline($prereqs, $dbProvisioner, $executor, app(SiteAuditWriter::class), placeholderDnsAlwaysAssigns()))->run($site->fresh());

    expect(implode("\n", $commands))->toContain("'/home/dply/my-wp-blog'");
});
