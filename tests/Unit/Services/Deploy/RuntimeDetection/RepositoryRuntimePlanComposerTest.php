<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Deploy\RuntimeDetection;

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
use PHPUnit\Framework\TestCase;

class RepositoryRuntimePlanComposerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/dply-plan-composer-'.uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
        parent::tearDown();
    }

    public function test_returns_null_for_empty_repo(): void
    {
        $this->assertNull($this->makeComposer()->compose($this->tempDir));
    }

    public function test_detection_only_repo_yields_detection_sourced_plan(): void
    {
        file_put_contents(
            $this->tempDir.'/composer.json',
            json_encode(['require' => ['laravel/framework' => '^11.0']]),
        );

        $plan = $this->makeComposer()->compose($this->tempDir);

        $this->assertNotNull($plan);
        $this->assertSame('php', $plan->runtime);
        $this->assertSame('laravel', $plan->framework);
        $this->assertSame('high', $plan->confidence);
        $this->assertSame(
            RepositoryRuntimePlan::SOURCE_DETECTION,
            $plan->fieldSource('runtime'),
        );
        $this->assertSame(
            RepositoryRuntimePlan::SOURCE_DETECTION,
            $plan->fieldSource('build_command'),
        );
        $this->assertFalse($plan->hasManifest());
    }

    public function test_manifest_runtime_overrides_detection(): void
    {
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

        $plan = $this->makeComposer()->compose($this->tempDir);

        $this->assertNotNull($plan);
        $this->assertSame('node', $plan->runtime);
        $this->assertSame('20.11', $plan->version);
        $this->assertSame(
            RepositoryRuntimePlan::SOURCE_MANIFEST,
            $plan->fieldSource('runtime'),
        );
        $this->assertSame(
            RepositoryRuntimePlan::SOURCE_MANIFEST,
            $plan->fieldSource('version'),
        );
        $this->assertSame('high', $plan->confidence);
    }

    public function test_manifest_only_yaml_with_no_repo_signals(): void
    {
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

        $plan = $this->makeComposer()->compose($this->tempDir);

        $this->assertNotNull($plan);
        $this->assertSame('python', $plan->runtime);
        $this->assertSame('3.12', $plan->version);
        $this->assertSame(
            'pip install -r requirements.txt && python manage.py collectstatic --noinput',
            $plan->buildCommand,
        );
        $this->assertSame(
            'gunicorn shop.wsgi:application --bind 0.0.0.0:8000',
            $plan->startCommand,
        );
        $this->assertCount(1, $plan->processes);
        $this->assertSame('worker', $plan->processes[0]->name);
        $this->assertSame('celery -A shop worker', $plan->processes[0]->command);
    }

    public function test_partial_manifest_fills_gaps_from_detection(): void
    {
        // Manifest pins runtime + version only. Build/start/processes inherit
        // from detection.
        $this->writeNodeRepoWithBullmq();
        file_put_contents(
            $this->tempDir.'/dply.yaml',
            "runtime: node\nversion: \"22.7.0\"\n",
        );

        $plan = $this->makeComposer()->compose($this->tempDir);

        $this->assertNotNull($plan);
        $this->assertSame('node', $plan->runtime);
        $this->assertSame('22.7.0', $plan->version);
        $this->assertSame('npm run build', $plan->buildCommand);
        $this->assertSame(
            RepositoryRuntimePlan::SOURCE_MANIFEST,
            $plan->fieldSource('runtime'),
        );
        $this->assertSame(
            RepositoryRuntimePlan::SOURCE_DETECTION,
            $plan->fieldSource('build_command'),
        );
        // BullMQ worker suggestion comes through from detection.
        $this->assertCount(1, $plan->processes);
        $this->assertSame('worker', $plan->processes[0]->name);
    }

    public function test_php_runtime_drops_start_and_app_port(): void
    {
        file_put_contents(
            $this->tempDir.'/composer.json',
            json_encode(['require' => ['laravel/framework' => '^11.0']]),
        );

        $plan = $this->makeComposer()->compose($this->tempDir);

        $this->assertNotNull($plan);
        $this->assertNull($plan->startCommand);
        $this->assertNull($plan->appPort);
    }

    public function test_php_manifest_web_command_is_ignored(): void
    {
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

        $plan = $this->makeComposer()->compose($this->tempDir);

        $this->assertNotNull($plan);
        $this->assertSame('php', $plan->runtime);
        $this->assertNull($plan->startCommand);
    }

    public function test_static_runtime_drops_start_and_app_port(): void
    {
        file_put_contents($this->tempDir.'/_config.yml', "title: Hi\n");

        $plan = $this->makeComposer()->compose($this->tempDir);

        $this->assertNotNull($plan);
        $this->assertSame('static', $plan->runtime);
        $this->assertSame('jekyll', $plan->framework);
        $this->assertNull($plan->startCommand);
        $this->assertNull($plan->appPort);
    }

    public function test_manifest_processes_win_over_detector_suggestions_with_same_name(): void
    {
        // Detector wants to suggest `worker` (BullMQ); manifest already
        // defines `worker` — the manifest's command wins, no duplicate row.
        $this->writeNodeRepoWithBullmq();
        file_put_contents(
            $this->tempDir.'/dply.yaml',
            <<<'YAML'
            runtime: node
            processes:
              worker:
                command: node my-custom-worker.js
            YAML,
        );

        $plan = $this->makeComposer()->compose($this->tempDir);

        $this->assertNotNull($plan);
        $this->assertCount(1, $plan->processes);
        $this->assertSame('worker', $plan->processes[0]->name);
        $this->assertSame('node my-custom-worker.js', $plan->processes[0]->command);
        $this->assertSame(SiteProcess::TYPE_WORKER, $plan->processes[0]->type);
        $this->assertStringContainsString('dply.yaml', $plan->processes[0]->reason);
    }

    public function test_manifest_scheduler_process_gets_scheduler_type(): void
    {
        file_put_contents(
            $this->tempDir.'/dply.yaml',
            <<<'YAML'
            runtime: php
            processes:
              scheduler:
                command: php artisan schedule:work
            YAML,
        );

        $plan = $this->makeComposer()->compose($this->tempDir);

        $this->assertNotNull($plan);
        $this->assertCount(1, $plan->processes);
        $this->assertSame(SiteProcess::TYPE_SCHEDULER, $plan->processes[0]->type);
    }

    public function test_falls_back_to_dply_yml_when_yaml_absent(): void
    {
        file_put_contents($this->tempDir.'/dply.yml', "runtime: go\nversion: \"1.22\"\n");

        $plan = $this->makeComposer()->compose($this->tempDir);

        $this->assertNotNull($plan);
        $this->assertSame('go', $plan->runtime);
        $this->assertSame('1.22', $plan->version);
    }

    public function test_yaml_takes_precedence_over_yml_when_both_exist(): void
    {
        file_put_contents($this->tempDir.'/dply.yml', "runtime: ruby\n");
        file_put_contents($this->tempDir.'/dply.yaml', "runtime: go\n");

        $plan = $this->makeComposer()->compose($this->tempDir);

        $this->assertNotNull($plan);
        $this->assertSame('go', $plan->runtime);
    }

    public function test_malformed_manifest_falls_back_to_detection_only(): void
    {
        file_put_contents(
            $this->tempDir.'/composer.json',
            json_encode(['require' => ['laravel/framework' => '^11.0']]),
        );
        // Triggers symfony/yaml ParseException → DplyManifestException.
        file_put_contents($this->tempDir.'/dply.yaml', "runtime: [unclosed\n");

        $plan = $this->makeComposer()->compose($this->tempDir);

        $this->assertNotNull($plan);
        $this->assertSame('php', $plan->runtime);
        $this->assertFalse($plan->hasManifest());
    }

    public function test_manifest_warnings_propagate_to_plan(): void
    {
        // Unknown top-level key → forward-compat warning from the parser.
        file_put_contents(
            $this->tempDir.'/dply.yaml',
            "runtime: node\ndatabase: postgres\n",
        );

        $plan = $this->makeComposer()->compose($this->tempDir);

        $this->assertNotNull($plan);
        $this->assertNotEmpty($plan->warnings);
        $this->assertStringContainsString('database', implode("\n", $plan->warnings));
    }

    public function test_reasons_combine_manifest_and_detection_signals(): void
    {
        $this->writeNodeRepoWithBullmq();
        file_put_contents(
            $this->tempDir.'/dply.yaml',
            "runtime: node\nversion: \"22.7.0\"\n",
        );

        $plan = $this->makeComposer()->compose($this->tempDir);

        $this->assertNotNull($plan);
        $combined = implode("\n", $plan->reasons);
        $this->assertStringContainsString('dply.yaml', $combined);
        $this->assertStringContainsString('package.json', $combined);
    }

    private function makeComposer(): RepositoryRuntimePlanComposer
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

    private function writeNodeRepoWithBullmq(): void
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

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
