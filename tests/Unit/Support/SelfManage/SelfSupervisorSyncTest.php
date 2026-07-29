<?php

declare(strict_types=1);

use App\Support\SelfManage\EnsuresSupervisordDplyRoot;
use App\Support\SelfManage\SelfSupervisorSync;
use App\Support\SelfManage\SupervisorIniSections;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->tmp = storage_path('framework/testing/self-supervisor-'.uniqid());
    File::ensureDirectoryExists($this->tmp.'/conf.d');
});

afterEach(function (): void {
    File::deleteDirectory($this->tmp);
});

test('SupervisorIniSections parses and round-trips program blocks', function () {
    $raw = <<<'INI'
; test template
[program:dply-horizon]
command=php artisan horizon
autorestart=true

[program:dply-warm]
command=php artisan warm
autorestart=false
startsecs=0

INI;
    $parsed = SupervisorIniSections::parse($raw);

    expect(SupervisorIniSections::programNames($parsed['sections']))
        ->toBe(['dply-horizon', 'dply-warm']);

    $rendered = SupervisorIniSections::render($parsed['preamble'], $parsed['sections']);
    $again = SupervisorIniSections::parse($rendered);
    expect(array_keys($again['sections']))->toBe(array_keys($parsed['sections']));
});

test('EnsuresSupervisordDplyRoot merges into existing environment line', function () {
    $helper = new EnsuresSupervisordDplyRoot;
    $conf = <<<'INI'
[unix_http_server]
file=/var/run/supervisor.sock

[supervisord]
logfile=/var/log/supervisor/supervisord.log
environment=FOO="bar"

INI;

    $patched = $helper->patchEnvironment($conf, '/var/www/dply/current');

    expect($patched)->toContain('DPLY_ROOT="/var/www/dply/current"')
        ->and($patched)->toContain('FOO="bar"');

    $again = $helper->patchEnvironment($patched, '/var/www/dply/current');
    expect($again)->toBe($patched);
});

test('SelfSupervisorSync merges preserve local-only programs and detects collisions', function () {
    // Isolate from repo root dply.yaml by pointing roles at a temp template
    // via a YAML the sync loads — use config fallback by temporarily moving
    // is awkward; instead write override through a custom base_path relative file
    // and stub loadSupervisorConfig via a partial mock.
    config()->set('dply_runtime.mode', 'worker');
    config()->set('dply_runtime.worker_role', 'primary');
    config()->set('self_manage.supervisor.use_templates', true);

    $rel = 'storage/framework/testing/self-sv-template-'.uniqid().'.conf';
    File::ensureDirectoryExists(dirname(base_path($rel)));
    File::put(base_path($rel), <<<'INI'
; test template
[program:dply-horizon]
command=php artisan horizon

[program:dply-warm]
command=php artisan warm
autorestart=false
startsecs=0

INI);

    $sync = Mockery::mock(SelfSupervisorSync::class, [app(EnsuresSupervisordDplyRoot::class)])
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    $sync->shouldReceive('loadSupervisorConfig')->andReturn([
        'use_templates' => true,
        'conf_d' => $this->tmp.'/conf.d',
        'install_as' => 'dply-platform.conf',
        'roles' => ['worker.primary' => $rel],
    ]);

    $owned = $this->tmp.'/conf.d/dply-platform.conf';
    File::put($owned, <<<'INI'
[program:local-only]
command=/bin/true

[program:dply-horizon]
command=php artisan horizon-old

INI);

    File::put($this->tmp.'/conf.d/legacy.conf', <<<'INI'
[program:dply-warm]
command=php artisan warm-legacy

INI);

    $blocked = $sync->sync(dryRun: true);
    expect($blocked['ok'])->toBeFalse()
        ->and($blocked['collisions'])->toHaveKey('dply-warm');

    $ok = $sync->sync(dryRun: true, adoptCollisions: true);
    expect($ok['ok'])->toBeTrue()
        ->and($ok['preserved'])->toContain('local-only')
        ->and($ok['managed'])->toContain('dply-horizon')
        ->and($ok['managed'])->toContain('dply-warm');

    @unlink(base_path($rel));
});

test('resolveRoleKey maps worker primary and replica', function () {
    $sync = app(SelfSupervisorSync::class);

    config()->set('dply_runtime.mode', 'worker');
    config()->set('dply_runtime.worker_role', 'primary');
    expect($sync->resolveRoleKey())->toBe('worker.primary');

    config()->set('dply_runtime.worker_role', 'replica');
    expect($sync->resolveRoleKey())->toBe('worker.replica');

    config()->set('dply_runtime.mode', 'web');
    expect($sync->resolveRoleKey())->toBe('web');
});
