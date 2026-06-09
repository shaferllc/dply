<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Deploy\RuntimeDetection\RubyRuntimeDetectorTest;

use App\Models\SiteProcess;
use App\Services\Deploy\RuntimeDetection\RubyRuntimeDetector;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/dply-ruby-detector-'.uniqid();
    mkdir($this->tempDir);
});
afterEach(function () {
    removeDir($this->tempDir);
});
test('runtime method returns ruby', function () {
    expect((new RubyRuntimeDetector)->runtime())->toBe('ruby');
});
test('returns null when no gemfile', function () {
    expect((new RubyRuntimeDetector)->detect($this->tempDir))->toBeNull();
});
test('minimal gemfile yields ruby runtime with medium confidence', function () {
    file_put_contents($this->tempDir.'/Gemfile', "source 'https://rubygems.org'\n");

    $result = (new RubyRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->runtime)->toBe('ruby');
    expect($result->framework)->toBe('ruby');
    expect($result->confidence)->toBe('medium');
    expect($result->buildCommand)->toBe('bundle install');
    expect($result->appPort)->toBe(3000);
});
test('pins version from tool versions first', function () {
    file_put_contents($this->tempDir.'/Gemfile', "ruby '3.0.0'\n");
    file_put_contents($this->tempDir.'/.tool-versions', "ruby 3.3.4\nnode 20\n");
    file_put_contents($this->tempDir.'/.ruby-version', "3.1.4\n");

    $result = (new RubyRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->version)->toBe('3.3.4');
    expect($result->detectedFiles)->toContain('.tool-versions');
});
test('falls back to ruby version file', function () {
    file_put_contents($this->tempDir.'/Gemfile', '');
    file_put_contents($this->tempDir.'/.ruby-version', "3.2.2\n");

    $result = (new RubyRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->version)->toBe('3.2.2');
});
test('strips ruby prefix from ruby version', function () {
    file_put_contents($this->tempDir.'/Gemfile', '');
    file_put_contents($this->tempDir.'/.ruby-version', "ruby-3.2.2\n");

    $result = (new RubyRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->version)->toBe('3.2.2');
});
test('falls back to gemfile ruby line', function () {
    file_put_contents($this->tempDir.'/Gemfile', "ruby \"3.2.4\"\ngem 'rails'\n");

    $result = (new RubyRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->version)->toBe('3.2.4');
});
test('detects rails from gemfile', function () {
    file_put_contents(
        $this->tempDir.'/Gemfile',
        "source 'https://rubygems.org'\ngem 'rails', '~> 7.1'\n",
    );

    $result = (new RubyRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->framework)->toBe('rails');
    expect($result->confidence)->toBe('high');
    expect($result->buildCommand)->toBe('bundle install && bundle exec rails assets:precompile');
});
test('detects rails from application rb when gemfile silent', function () {
    file_put_contents($this->tempDir.'/Gemfile', '');
    mkdir($this->tempDir.'/config');
    file_put_contents($this->tempDir.'/config/application.rb', "module App\nend\n");

    $result = (new RubyRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->framework)->toBe('rails');
    expect($result->detectedFiles)->toContain('config/application.rb');
});
test('rails uses puma config when present', function () {
    file_put_contents($this->tempDir.'/Gemfile', "gem 'rails'\n");
    mkdir($this->tempDir.'/config');
    file_put_contents($this->tempDir.'/config/puma.rb', '');

    $result = (new RubyRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->startCommand)->toBe('bundle exec puma -C config/puma.rb');
});
test('rails falls back to rails server when no puma config', function () {
    file_put_contents($this->tempDir.'/Gemfile', "gem 'rails'\n");

    $result = (new RubyRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    $this->assertStringContainsString('rails server', $result->startCommand ?? '');
});
test('detects sinatra', function () {
    file_put_contents($this->tempDir.'/Gemfile', "gem 'sinatra'\n");
    file_put_contents($this->tempDir.'/config.ru', "require './app'\nrun Sinatra::Application\n");

    $result = (new RubyRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->framework)->toBe('sinatra');
    $this->assertStringContainsString('rackup', $result->startCommand ?? '');
});
test('suggests sidekiq worker when config yml present', function () {
    file_put_contents($this->tempDir.'/Gemfile', "gem 'rails'\ngem 'sidekiq'\n");
    mkdir($this->tempDir.'/config');
    file_put_contents($this->tempDir.'/config/sidekiq.yml', ":queues:\n  - default\n");

    $result = (new RubyRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->processes)->toHaveCount(1);
    $process = $result->processes[0];
    expect($process->type)->toBe(SiteProcess::TYPE_WORKER);
    expect($process->name)->toBe('sidekiq');
    expect($process->command)->toBe('bundle exec sidekiq -C config/sidekiq.yml');
    expect($result->detectedFiles)->toContain('config/sidekiq.yml');
});
test('suggests sidekiq worker with initializer only', function () {
    file_put_contents($this->tempDir.'/Gemfile', "gem 'rails'\ngem 'sidekiq'\n");
    mkdir($this->tempDir.'/config/initializers', 0o755, true);
    file_put_contents($this->tempDir.'/config/initializers/sidekiq.rb', "Sidekiq.configure_server {}\n");

    $result = (new RubyRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->processes)->toHaveCount(1);
    expect($result->processes[0]->command)->toBe('bundle exec sidekiq');
});
test('does not suggest sidekiq worker when only gem present', function () {
    file_put_contents($this->tempDir.'/Gemfile', "gem 'rails'\ngem 'sidekiq'\n");

    $result = (new RubyRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->processes)->toBe([]);
});
test('does not suggest sidekiq worker when only config present', function () {
    file_put_contents($this->tempDir.'/Gemfile', "gem 'rails'\n");
    mkdir($this->tempDir.'/config');
    file_put_contents($this->tempDir.'/config/sidekiq.yml', '');

    $result = (new RubyRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->processes)->toBe([]);
});
test('reasons describe each inference', function () {
    file_put_contents(
        $this->tempDir.'/Gemfile',
        "source 'https://rubygems.org'\nruby '3.2.0'\ngem 'rails', '~> 7.1'\ngem 'sidekiq'\n",
    );
    mkdir($this->tempDir.'/config');
    file_put_contents($this->tempDir.'/config/sidekiq.yml', '');

    $result = (new RubyRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    $combined = implode("\n", $result->reasons);
    $this->assertStringContainsString('Gemfile', $combined);
    $this->assertStringContainsString('rails', $combined);
    $this->assertStringContainsString('sidekiq', $combined);
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
