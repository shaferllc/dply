<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Deploy\RuntimeDetection;

use App\Models\SiteProcess;
use App\Services\Deploy\RuntimeDetection\PythonRuntimeDetector;
use PHPUnit\Framework\TestCase;

class PythonRuntimeDetectorTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/dply-python-detector-'.uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
        parent::tearDown();
    }

    public function test_returns_null_when_no_python_files(): void
    {
        $result = (new PythonRuntimeDetector)->detect($this->tempDir);

        $this->assertNull($result);
    }

    public function test_runtime_method_returns_python(): void
    {
        $this->assertSame('python', (new PythonRuntimeDetector)->runtime());
    }

    public function test_minimal_requirements_txt_yields_python_runtime_with_medium_confidence(): void
    {
        file_put_contents($this->tempDir.'/requirements.txt', "click==8.1.7\n");

        $result = (new PythonRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame('python', $result->runtime);
        $this->assertSame('python', $result->framework);
        $this->assertSame('medium', $result->confidence);
        $this->assertSame('pip install -r requirements.txt', $result->buildCommand);
        $this->assertNull($result->startCommand);
        $this->assertContains('requirements.txt', $result->detectedFiles);
    }

    public function test_pins_version_from_tool_versions_first(): void
    {
        file_put_contents($this->tempDir.'/requirements.txt', '');
        file_put_contents($this->tempDir.'/.tool-versions', "python 3.12.4\nnode 20\n");
        file_put_contents($this->tempDir.'/.python-version', "3.10\n");

        $result = (new PythonRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame('3.12.4', $result->version);
        $this->assertContains('.tool-versions', $result->detectedFiles);
    }

    public function test_falls_back_to_python_version_file(): void
    {
        file_put_contents($this->tempDir.'/requirements.txt', '');
        file_put_contents($this->tempDir.'/.python-version', "3.11.6\n");

        $result = (new PythonRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame('3.11.6', $result->version);
    }

    public function test_falls_back_to_pyproject_requires_python(): void
    {
        file_put_contents(
            $this->tempDir.'/pyproject.toml',
            "[project]\nname = \"app\"\nrequires-python = \">=3.10\"\n",
        );

        $result = (new PythonRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame('>=3.10', $result->version);
    }

    public function test_falls_back_to_poetry_python_dep(): void
    {
        file_put_contents(
            $this->tempDir.'/pyproject.toml',
            <<<'TOML'
            [tool.poetry]
            name = "app"

            [tool.poetry.dependencies]
            python = "^3.11"
            requests = "^2.31"
            TOML,
        );

        $result = (new PythonRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame('^3.11', $result->version);
    }

    public function test_detects_django_from_manage_py(): void
    {
        file_put_contents($this->tempDir.'/requirements.txt', '');
        file_put_contents(
            $this->tempDir.'/manage.py',
            <<<'PY'
            #!/usr/bin/env python
            import os
            def main():
                os.environ.setdefault('DJANGO_SETTINGS_MODULE', 'mysite.settings')
            PY,
        );

        $result = (new PythonRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame('django', $result->framework);
        $this->assertSame('high', $result->confidence);
        $this->assertSame(
            'gunicorn mysite.wsgi:application --bind 0.0.0.0:8000',
            $result->startCommand,
        );
        $this->assertContains('manage.py', $result->detectedFiles);
        $this->assertSame(8000, $result->appPort);
    }

    public function test_detects_django_from_dependencies_without_manage_py(): void
    {
        file_put_contents($this->tempDir.'/requirements.txt', "Django>=5.0\n");

        $result = (new PythonRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame('django', $result->framework);
        // No manage.py to read project name from — fall back to placeholder.
        $this->assertStringContainsString('<project>', $result->startCommand ?? '');
    }

    public function test_detects_fastapi_with_main_py(): void
    {
        file_put_contents($this->tempDir.'/requirements.txt', "fastapi==0.110\nuvicorn\n");
        file_put_contents($this->tempDir.'/main.py', '');

        $result = (new PythonRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame('fastapi', $result->framework);
        $this->assertSame(
            'uvicorn main:app --host 0.0.0.0 --port 8000',
            $result->startCommand,
        );
        $this->assertSame(8000, $result->appPort);
    }

    public function test_fastapi_falls_back_to_app_module_when_no_main_py(): void
    {
        file_put_contents($this->tempDir.'/requirements.txt', "fastapi\n");

        $result = (new PythonRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame('fastapi', $result->framework);
        $this->assertStringContainsString('app:app', $result->startCommand ?? '');
    }

    public function test_detects_flask(): void
    {
        file_put_contents($this->tempDir.'/requirements.txt', "Flask==3.0\n");
        file_put_contents($this->tempDir.'/app.py', '');

        $result = (new PythonRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame('flask', $result->framework);
        $this->assertStringContainsString('app:app', $result->startCommand ?? '');
    }

    public function test_pipfile_yields_pipenv_build(): void
    {
        file_put_contents(
            $this->tempDir.'/Pipfile',
            <<<'TOML'
            [packages]
            django = "*"
            celery = "*"
            TOML,
        );
        file_put_contents(
            $this->tempDir.'/manage.py',
            "os.environ.setdefault('DJANGO_SETTINGS_MODULE', 'shop.settings')",
        );

        $result = (new PythonRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame('pipenv install --deploy --ignore-pipfile', $result->buildCommand);
        $this->assertSame('django', $result->framework);
    }

    public function test_poetry_yields_poetry_build(): void
    {
        file_put_contents(
            $this->tempDir.'/pyproject.toml',
            <<<'TOML'
            [tool.poetry]
            name = "app"

            [tool.poetry.dependencies]
            python = "^3.12"
            fastapi = "^0.110"
            TOML,
        );

        $result = (new PythonRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame('poetry install --without dev', $result->buildCommand);
        $this->assertSame('fastapi', $result->framework);
    }

    public function test_pep_621_pyproject_yields_pip_install_dot(): void
    {
        file_put_contents(
            $this->tempDir.'/pyproject.toml',
            <<<'TOML'
            [project]
            name = "app"
            requires-python = ">=3.11"
            dependencies = ["fastapi>=0.110", "uvicorn"]
            TOML,
        );

        $result = (new PythonRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame('pip install .', $result->buildCommand);
        $this->assertSame('fastapi', $result->framework);
    }

    public function test_suggests_celery_worker_for_django_celery_combo(): void
    {
        file_put_contents($this->tempDir.'/requirements.txt', "Django\ncelery>=5\n");
        file_put_contents(
            $this->tempDir.'/manage.py',
            "os.environ.setdefault('DJANGO_SETTINGS_MODULE', 'shop.settings')",
        );

        $result = (new PythonRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertCount(1, $result->processes);
        $process = $result->processes[0];
        $this->assertSame(SiteProcess::TYPE_WORKER, $process->type);
        $this->assertSame('celery', $process->name);
        $this->assertSame('celery -A shop worker --loglevel=info', $process->command);
    }

    public function test_does_not_suggest_celery_when_only_celery_dep(): void
    {
        file_put_contents($this->tempDir.'/requirements.txt', "celery>=5\n");

        $result = (new PythonRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame([], $result->processes);
    }

    public function test_does_not_suggest_celery_when_django_project_unknown(): void
    {
        // Django dep but no manage.py — we can't synthesize a working `-A` arg.
        file_put_contents($this->tempDir.'/requirements.txt', "Django\ncelery\n");

        $result = (new PythonRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame([], $result->processes);
    }

    public function test_skips_comments_and_options_in_requirements(): void
    {
        file_put_contents(
            $this->tempDir.'/requirements.txt',
            "# top comment\n--index-url https://example.com\n-r other.txt\nfastapi==0.110\n",
        );

        $result = (new PythonRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame('fastapi', $result->framework);
    }

    public function test_reasons_describe_each_inference(): void
    {
        file_put_contents($this->tempDir.'/requirements.txt', "Django>=5.0\ncelery\n");
        file_put_contents(
            $this->tempDir.'/manage.py',
            "os.environ.setdefault('DJANGO_SETTINGS_MODULE', 'shop.settings')",
        );
        file_put_contents($this->tempDir.'/.python-version', "3.12.4\n");

        $result = (new PythonRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $combined = implode("\n", $result->reasons);
        $this->assertStringContainsString('requirements.txt', $combined);
        $this->assertStringContainsString('manage.py', $combined);
        $this->assertStringContainsString('.python-version', $combined);
        $this->assertStringContainsString('django', $combined);
        $this->assertStringContainsString('celery', $combined);
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
