<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\VmSiteRuntimeCorrectionTest;

use App\Contracts\RemoteShell;
use App\Enums\SiteType;
use App\Jobs\ApplySiteWebserverConfigJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Sites\VmSiteStackDetectionPersister;
use Illuminate\Support\Facades\Queue;

/**
 * A Node app served by a document-root vhost answers 404 to everything, with no
 * error anywhere to explain it — the app is fine, nginx was simply never told
 * to proxy to it. Detection already ran on every deploy; nothing applied it.
 */
function fakeShell(array $files): RemoteShell
{
    return new class($files) implements RemoteShell
    {
        public function __construct(private array $files) {}

        public function exec(string $command, int $timeoutSeconds = 120): string
        {
            // RemoteRepositoryFiles probes with one `find … -printf` listing and
            // then `cat`s the files it wants, so the fake answers both.
            if (str_contains($command, 'find ')) {
                return implode("\n", array_keys($this->files));
            }

            foreach ($this->files as $path => $contents) {
                if (str_contains($command, '/'.$path)) {
                    return (string) $contents;
                }
            }

            return '';
        }

        public function putFile(string $remotePath, string $contents, int $timeoutSeconds = 60): void {}
    };
}

/**
 * A server with Node actually installed. SetSiteRuntime refuses a runtime the
 * box does not have — rightly, since a vhost proxying to a process that can
 * never start is worse than the 404 it replaced.
 */
function vmServerWithNode(User $user): Server
{
    return Server::factory()->create([
        'user_id' => $user->id,
        'status' => 'ready',
        'meta' => ['manage_mise_runtimes' => ['node' => ['active' => '22.11.0', 'versions' => ['22.11.0']]]],
    ]);
}

function siteOn(Server $server, array $attributes = []): Site
{
    return Site::factory()->create(array_merge([
        // A deployed site: `lacksInstalledApp()` blocks a switch on a site with
        // no code, which is a different (correct) refusal from the one here.
        'git_repository_url' => 'git@github.com:acme/app.git',
        'server_id' => $server->id,
        'user_id' => $server->user_id,
        'organization_id' => $server->organization_id,
        'runtime' => 'php',
        'type' => SiteType::Php,
    ], $attributes));
}

beforeEach(function () {
    Queue::fake();
});

test('a node app on a php-typed site is corrected so nginx proxies to it', function () {
    $user = User::factory()->create();
    $server = vmServerWithNode($user);
    $site = siteOn($server, ['start_command' => 'npm start', 'internal_port' => '3000']);

    app(VmSiteStackDetectionPersister::class)->persistFromReleasePath(
        $site,
        fakeShell(['package.json' => json_encode(['name' => 'app', 'scripts' => ['start' => 'node server.js']])]),
        '/home/dply/site/current',
    );

    $site->refresh();

    expect($site->runtime)->toBe('node');
    expect($site->type)->toBe(SiteType::Node);
    expect(data_get($site->meta, 'vm_runtime.corrected.applied'))->toBeTrue();

    // The correction is worthless until the vhost is rewritten.
    Queue::assertPushed(ApplySiteWebserverConfigJob::class);
})->group('sites');

test('a laravel app is left alone even though it has a package.json', function () {
    // THE regression this must never cause: every Laravel app ships a
    // package.json for Vite, so "has package.json" would convert them all to a
    // proxy and take them down.
    $user = User::factory()->create();
    $server = vmServerWithNode($user);
    $site = siteOn($server);

    app(VmSiteStackDetectionPersister::class)->persistFromReleasePath(
        $site,
        fakeShell([
            'composer.json' => json_encode(['require' => ['laravel/framework' => '^12.0']]),
            'package.json' => json_encode(['devDependencies' => ['vite' => '^5.0']]),
            'artisan' => '#!/usr/bin/env php',
        ]),
        '/home/dply/site/current',
    );

    $site->refresh();

    expect($site->runtime)->toBe('php');
    expect($site->type)->toBe(SiteType::Php);
    Queue::assertNotPushed(ApplySiteWebserverConfigJob::class);
})->group('sites');

test('a site already on a proxied runtime is never demoted', function () {
    $user = User::factory()->create();
    $server = vmServerWithNode($user);
    $site = siteOn($server, [
        'runtime' => 'node',
        'type' => SiteType::Node,
        'start_command' => 'npm start',
        'internal_port' => '3000',
    ]);

    app(VmSiteStackDetectionPersister::class)->persistFromReleasePath(
        $site,
        fakeShell([
            'composer.json' => json_encode(['require' => ['laravel/framework' => '^12.0']]),
            'artisan' => '#!/usr/bin/env php',
        ]),
        '/home/dply/site/current',
    );

    $site->refresh();

    expect($site->runtime)->toBe('node');
    expect($site->type)->toBe(SiteType::Node);
})->group('sites');

test('a refusal is recorded rather than failing the deploy', function () {
    // No start command and no app: SetSiteRuntime refuses, and it is right to.
    // Publishing a proxy to a process that cannot exist turns a 404 into a 502.
    $user = User::factory()->create();
    $server = vmServerWithNode($user);
    $site = siteOn($server, ['start_command' => null, 'internal_port' => null]);

    app(VmSiteStackDetectionPersister::class)->persistFromReleasePath(
        $site,
        fakeShell(['package.json' => json_encode(['name' => 'app'])]),
        '/home/dply/site/current',
    );

    $site->refresh();

    expect($site->runtime)->toBe('php');
    expect(data_get($site->meta, 'vm_runtime.corrected.applied'))->toBeFalse();
    expect((string) data_get($site->meta, 'vm_runtime.corrected.reason'))->not->toBe('');
})->group('sites');
