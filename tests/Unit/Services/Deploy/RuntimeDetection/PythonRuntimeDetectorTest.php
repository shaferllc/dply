<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Deploy\RuntimeDetection\PythonRuntimeDetectorTest;
use App\Models\SiteProcess;
use App\Services\Deploy\RuntimeDetection\PythonRuntimeDetector;
beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/dply-python-detector-'.uniqid();
    mkdir($this->tempDir);
});
afterEach(function () {
    removeDir($this->tempDir);
});
test('returns null when no python files', function () {
    $result = (new PythonRuntimeDetector)->detect($this->tempDir);

    expect($result)->toBeNull();
});
test('runtime method returns python', function () {
    expect((new PythonRuntimeDetector)->runtime())->toBe('python');
});
test('minimal requirements txt yields python runtime with medium confidence', function () {
    file_put_contents($this->tempDir.'/requirements.txt', "click==8.1.7\n");

    $result = (new PythonRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->runtime)->toBe('python');
    expect($result->framework)->toBe('python');
    expect($result->confidence)->toBe('medium');
    expect($result->buildCommand)->toBe('pip install -r requirements.txt');
    expect($result->startCommand)->toBeNull();
    expect($result->detectedFiles)->toContain('requirements.txt');
});
test('pins version from tool versions first', function () {
    file_put_contents($this->tempDir.'/requirements.txt', '');
    file_put_contents($this->tempDir.'/.tool-versions', "python 3.12.4\nnode 20\n");
    file_put_contents($this->tempDir.'/.python-version', "3.10\n");

    $result = (new PythonRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->version)->toBe('3.12.4');
    expect($result->detectedFiles)->toContain('.tool-versions');
});
test('falls back to python version file', function () {
    file_put_contents($this->tempDir.'/requirements.txt', '');
    file_put_contents($this->tempDir.'/.python-version', "3.11.6\n");

    $result = (new PythonRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->version)->toBe('3.11.6');
});
test('falls back to pyproject requires python', function () {
    file_put_contents(
        $this->tempDir.'/pyproject.toml',
        "[project]\nname = \"app\"\nrequires-python = \">=3.10\"\n",
    );

    $result = (new PythonRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->version)->toBe('>=3.10');
});
test('falls back to poetry python dep', function () {
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

    expect($result)->not->toBeNull();
    expect($result->version)->toBe('^3.11');
});
test('detects django from manage py', function () {
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

    expect($result)->not->toBeNull();
    expect($result->framework)->toBe('django');
    expect($result->confidence)->toBe('high');
    expect($result->startCommand)->toBe('gunicorn mysite.wsgi:application --bind 0.0.0.0:8000');
    expect($result->detectedFiles)->toContain('manage.py');
    expect($result->appPort)->toBe(8000);
});
test('detects django from dependencies without manage py', function () {
    file_put_contents($this->tempDir.'/requirements.txt', "Django>=5.0\n");

    $result = (new PythonRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->framework)->toBe('django');

    // No manage.py to read project name from — fall back to placeholder.
    $this->assertStringContainsString('<project>', $result->startCommand ?? '');
});
test('detects fastapi with main py', function () {
    file_put_contents($this->tempDir.'/requirements.txt', "fastapi==0.110\nuvicorn\n");
    file_put_contents($this->tempDir.'/main.py', '');

    $result = (new PythonRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->framework)->toBe('fastapi');
    expect($result->startCommand)->toBe('uvicorn main:app --host 0.0.0.0 --port 8000');
    expect($result->appPort)->toBe(8000);
});
test('fastapi falls back to app module when no main py', function () {
    file_put_contents($this->tempDir.'/requirements.txt', "fastapi\n");

    $result = (new PythonRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->framework)->toBe('fastapi');
    $this->assertStringContainsString('app:app', $result->startCommand ?? '');
});
test('detects flask', function () {
    file_put_contents($this->tempDir.'/requirements.txt', "Flask==3.0\n");
    file_put_contents($this->tempDir.'/app.py', '');

    $result = (new PythonRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->framework)->toBe('flask');
    $this->assertStringContainsString('app:app', $result->startCommand ?? '');
});
test('pipfile yields pipenv build', function () {
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

    expect($result)->not->toBeNull();
    expect($result->buildCommand)->toBe('pipenv install --deploy --ignore-pipfile');
    expect($result->framework)->toBe('django');
});
test('poetry yields poetry build', function () {
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

    expect($result)->not->toBeNull();
    expect($result->buildCommand)->toBe('poetry install --without dev');
    expect($result->framework)->toBe('fastapi');
});
test('pep 621 pyproject yields pip install dot', function () {
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

    expect($result)->not->toBeNull();
    expect($result->buildCommand)->toBe('pip install .');
    expect($result->framework)->toBe('fastapi');
});
test('suggests celery worker for django celery combo', function () {
    file_put_contents($this->tempDir.'/requirements.txt', "Django\ncelery>=5\n");
    file_put_contents(
        $this->tempDir.'/manage.py',
        "os.environ.setdefault('DJANGO_SETTINGS_MODULE', 'shop.settings')",
    );

    $result = (new PythonRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->processes)->toHaveCount(1);
    $process = $result->processes[0];
    expect($process->type)->toBe(SiteProcess::TYPE_WORKER);
    expect($process->name)->toBe('celery');
    expect($process->command)->toBe('celery -A shop worker --loglevel=info');
});
test('does not suggest celery when only celery dep', function () {
    file_put_contents($this->tempDir.'/requirements.txt', "celery>=5\n");

    $result = (new PythonRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->processes)->toBe([]);
});
test('does not suggest celery when django project unknown', function () {
    // Django dep but no manage.py — we can't synthesize a working `-A` arg.
    file_put_contents($this->tempDir.'/requirements.txt', "Django\ncelery\n");

    $result = (new PythonRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->processes)->toBe([]);
});
test('skips comments and options in requirements', function () {
    file_put_contents(
        $this->tempDir.'/requirements.txt',
        "# top comment\n--index-url https://example.com\n-r other.txt\nfastapi==0.110\n",
    );

    $result = (new PythonRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->framework)->toBe('fastapi');
});
test('reasons describe each inference', function () {
    file_put_contents($this->tempDir.'/requirements.txt', "Django>=5.0\ncelery\n");
    file_put_contents(
        $this->tempDir.'/manage.py',
        "os.environ.setdefault('DJANGO_SETTINGS_MODULE', 'shop.settings')",
    );
    file_put_contents($this->tempDir.'/.python-version', "3.12.4\n");

    $result = (new PythonRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    $combined = implode("\n", $result->reasons);
    $this->assertStringContainsString('requirements.txt', $combined);
    $this->assertStringContainsString('manage.py', $combined);
    $this->assertStringContainsString('.python-version', $combined);
    $this->assertStringContainsString('django', $combined);
    $this->assertStringContainsString('celery', $combined);
});
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
