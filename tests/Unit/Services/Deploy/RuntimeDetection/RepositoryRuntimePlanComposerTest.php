<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Deploy\RuntimeDetection\RepositoryRuntimePlanComposerTest;
use App\Models\SiteProcess;
use App\Services\Deploy\Manifest\DplyManifestParser;
use App\Services\Deploy\RuntimeDetection\GoRuntimeDetector;
use App\Services\Deploy\RuntimeDetection\NodeRuntimeDetector;
use App\Services\Deploy\RuntimeDetection\PhpRuntimeDetector;
use App\Services\Deploy\RuntimeDetection\PythonRuntimeDetector;
use App\Services\Deploy\RuntimeDetection\RepositoryRuntimePlan;
use App\Services\Deploy\RuntimeDetection\RepositoryRuntimePlanComposer;
use App\Services\Deploy\RuntimeDetection\RubyRuntimeDetector;
use App\Services\Deploy\RuntimeDetection\RuntimeDetectionEngine;
use App\Services\Deploy\RuntimeDetection\StaticRuntimeDetector;
beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/dply-plan-composer-'.uniqid();
    mkdir($this->tempDir);
});
afterEach(function () {
    removeDir($this->tempDir);
});
test('returns null for empty repo', function () {
    expect(makeComposer()->compose($this->tempDir))->toBeNull();
});
test('detection only repo yields detection sourced plan', function () {
    file_put_contents(
        $this->tempDir.'/composer.json',
        json_encode(['require' => ['laravel/framework' => '^11.0']]),
    );

    $plan = makeComposer()->compose($this->tempDir);

    expect($plan)->not->toBeNull();
    expect($plan->runtime)->toBe('php');
    expect($plan->framework)->toBe('laravel');
    expect($plan->confidence)->toBe('high');
    expect($plan->fieldSource('runtime'))->toBe(RepositoryRuntimePlan::SOURCE_DETECTION);
    expect($plan->fieldSource('build_command'))->toBe(RepositoryRuntimePlan::SOURCE_DETECTION);
    expect($plan->hasManifest())->toBeFalse();
});
test('manifest runtime overrides detection', function () {
    // composer.json says PHP/Laravel, but the manifest says node.
    // The user's explicit choice wins.
    file_put_contents(
        $this->tempDir.'/composer.json',
        json_encode(['require' => ['laravel/framework' => '^11.0']]),
    );
    file_put_contents(
        $this->tempDir.'/dply.yaml',
        "runtime: node\nversion: \"20.11\"\n",
    );

    $plan = makeComposer()->compose($this->tempDir);

    expect($plan)->not->toBeNull();
    expect($plan->runtime)->toBe('node');
    expect($plan->version)->toBe('20.11');
    expect($plan->fieldSource('runtime'))->toBe(RepositoryRuntimePlan::SOURCE_MANIFEST);
    expect($plan->fieldSource('version'))->toBe(RepositoryRuntimePlan::SOURCE_MANIFEST);
    expect($plan->confidence)->toBe('high');
});
test('manifest only yaml with no repo signals', function () {
    // Pure manifest, no detector signals — manifest must drive the plan.
    file_put_contents(
        $this->tempDir.'/dply.yaml',
        <<<'YAML'
            runtime: python
            version: "3.12"
            build:
              - pip install -r requirements.txt
              - python manage.py collectstatic --noinput
            processes:
              web:
                command: gunicorn shop.wsgi:application --bind 0.0.0.0:8000
              worker:
                command: celery -A shop worker
            YAML,
    );

    $plan = makeComposer()->compose($this->tempDir);

    expect($plan)->not->toBeNull();
    expect($plan->runtime)->toBe('python');
    expect($plan->version)->toBe('3.12');
    expect($plan->buildCommand)->toBe('pip install -r requirements.txt && python manage.py collectstatic --noinput');
    expect($plan->startCommand)->toBe('gunicorn shop.wsgi:application --bind 0.0.0.0:8000');
    expect($plan->processes)->toHaveCount(1);
    expect($plan->processes[0]->name)->toBe('worker');
    expect($plan->processes[0]->command)->toBe('celery -A shop worker');
});
test('partial manifest fills gaps from detection', function () {
    // Manifest pins runtime + version only. Build/start/processes inherit
    // from detection.
    writeNodeRepoWithBullmq();
    file_put_contents(
        $this->tempDir.'/dply.yaml',
        "runtime: node\nversion: \"22.7.0\"\n",
    );

    $plan = makeComposer()->compose($this->tempDir);

    expect($plan)->not->toBeNull();
    expect($plan->runtime)->toBe('node');
    expect($plan->version)->toBe('22.7.0');
    expect($plan->buildCommand)->toBe('npm run build');
    expect($plan->fieldSource('runtime'))->toBe(RepositoryRuntimePlan::SOURCE_MANIFEST);
    expect($plan->fieldSource('build_command'))->toBe(RepositoryRuntimePlan::SOURCE_DETECTION);

    // BullMQ worker suggestion comes through from detection.
    expect($plan->processes)->toHaveCount(1);
    expect($plan->processes[0]->name)->toBe('worker');
});
test('php runtime drops start and app port', function () {
    file_put_contents(
        $this->tempDir.'/composer.json',
        json_encode(['require' => ['laravel/framework' => '^11.0']]),
    );

    $plan = makeComposer()->compose($this->tempDir);

    expect($plan)->not->toBeNull();
    expect($plan->startCommand)->toBeNull();
    expect($plan->appPort)->toBeNull();
});
test('php manifest web command is ignored', function () {
    // Even if a user puts a `web` process in their PHP manifest, FPM
    // is implicit — the start command stays null.
    file_put_contents(
        $this->tempDir.'/composer.json',
        json_encode(['require' => ['laravel/framework' => '^11.0']]),
    );
    file_put_contents(
        $this->tempDir.'/dply.yaml',
        "runtime: php\nprocesses:\n  web:\n    command: php -S 0.0.0.0:8000\n",
    );

    $plan = makeComposer()->compose($this->tempDir);

    expect($plan)->not->toBeNull();
    expect($plan->runtime)->toBe('php');
    expect($plan->startCommand)->toBeNull();
});
test('static runtime drops start and app port', function () {
    file_put_contents($this->tempDir.'/_config.yml', "title: Hi\n");

    $plan = makeComposer()->compose($this->tempDir);

    expect($plan)->not->toBeNull();
    expect($plan->runtime)->toBe('static');
    expect($plan->framework)->toBe('jekyll');
    expect($plan->startCommand)->toBeNull();
    expect($plan->appPort)->toBeNull();
});
test('manifest processes win over detector suggestions with same name', function () {
    // Detector wants to suggest `worker` (BullMQ); manifest already
    // defines `worker` — the manifest's command wins, no duplicate row.
    writeNodeRepoWithBullmq();
    file_put_contents(
        $this->tempDir.'/dply.yaml',
        <<<'YAML'
            runtime: node
            processes:
              worker:
                command: node my-custom-worker.js
            YAML,
    );

    $plan = makeComposer()->compose($this->tempDir);

    expect($plan)->not->toBeNull();
    expect($plan->processes)->toHaveCount(1);
    expect($plan->processes[0]->name)->toBe('worker');
    expect($plan->processes[0]->command)->toBe('node my-custom-worker.js');
    expect($plan->processes[0]->type)->toBe(SiteProcess::TYPE_WORKER);
    $this->assertStringContainsString('dply.yaml', $plan->processes[0]->reason);
});
test('manifest scheduler process gets scheduler type', function () {
    file_put_contents(
        $this->tempDir.'/dply.yaml',
        <<<'YAML'
            runtime: php
            processes:
              scheduler:
                command: php artisan schedule:work
            YAML,
    );

    $plan = makeComposer()->compose($this->tempDir);

    expect($plan)->not->toBeNull();
    expect($plan->processes)->toHaveCount(1);
    expect($plan->processes[0]->type)->toBe(SiteProcess::TYPE_SCHEDULER);
});
test('falls back to dply yml when yaml absent', function () {
    file_put_contents($this->tempDir.'/dply.yml', "runtime: go\nversion: \"1.22\"\n");

    $plan = makeComposer()->compose($this->tempDir);

    expect($plan)->not->toBeNull();
    expect($plan->runtime)->toBe('go');
    expect($plan->version)->toBe('1.22');
});
test('yaml takes precedence over yml when both exist', function () {
    file_put_contents($this->tempDir.'/dply.yml', "runtime: ruby\n");
    file_put_contents($this->tempDir.'/dply.yaml', "runtime: go\n");

    $plan = makeComposer()->compose($this->tempDir);

    expect($plan)->not->toBeNull();
    expect($plan->runtime)->toBe('go');
});
test('malformed manifest falls back to detection only', function () {
    file_put_contents(
        $this->tempDir.'/composer.json',
        json_encode(['require' => ['laravel/framework' => '^11.0']]),
    );

    // Triggers symfony/yaml ParseException → DplyManifestException.
    file_put_contents($this->tempDir.'/dply.yaml', "runtime: [unclosed\n");

    $plan = makeComposer()->compose($this->tempDir);

    expect($plan)->not->toBeNull();
    expect($plan->runtime)->toBe('php');
    expect($plan->hasManifest())->toBeFalse();
});
test('manifest warnings propagate to plan', function () {
    // Unknown top-level key → forward-compat warning from the parser.
    file_put_contents(
        $this->tempDir.'/dply.yaml',
        "runtime: node\ndatabase: postgres\n",
    );

    $plan = makeComposer()->compose($this->tempDir);

    expect($plan)->not->toBeNull();
    expect($plan->warnings)->not->toBeEmpty();
    $this->assertStringContainsString('database', implode("\n", $plan->warnings));
});
test('reasons combine manifest and detection signals', function () {
    writeNodeRepoWithBullmq();
    file_put_contents(
        $this->tempDir.'/dply.yaml',
        "runtime: node\nversion: \"22.7.0\"\n",
    );

    $plan = makeComposer()->compose($this->tempDir);

    expect($plan)->not->toBeNull();
    $combined = implode("\n", $plan->reasons);
    $this->assertStringContainsString('dply.yaml', $combined);
    $this->assertStringContainsString('package.json', $combined);
});
function makeComposer(): RepositoryRuntimePlanComposer
{
    return new RepositoryRuntimePlanComposer(
        new RuntimeDetectionEngine([
            new PhpRuntimeDetector,
            new NodeRuntimeDetector,
            new PythonRuntimeDetector,
            new RubyRuntimeDetector,
            new GoRuntimeDetector,
            new StaticRuntimeDetector,
        ]),
        new DplyManifestParser,
    );
}
function writeNodeRepoWithBullmq(): void
{
    file_put_contents(
        $this->tempDir.'/package.json',
        json_encode([
            'name' => 'jobs-app',
            'dependencies' => ['bullmq' => '^5.0'],
            'scripts' => [
                'build' => 'tsc',
                'start' => 'node dist/server.js',
                'worker' => 'node dist/worker.js',
            ],
        ]),
    );
}
function removeDir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir.'/'.$entry;
        is_dir($path) ? removeDir($path) : @unlink($path);
    }
    @rmdir($dir);
}
