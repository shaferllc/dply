<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Deploy\RuntimeDetection;

use App\Models\SiteProcess;
use App\Services\Deploy\RuntimeDetection\RubyRuntimeDetector;
use PHPUnit\Framework\TestCase;

class RubyRuntimeDetectorTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/dply-ruby-detector-'.uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
        parent::tearDown();
    }

    public function test_runtime_method_returns_ruby(): void
    {
        $this->assertSame('ruby', (new RubyRuntimeDetector)->runtime());
    }

    public function test_returns_null_when_no_gemfile(): void
    {
        $this->assertNull((new RubyRuntimeDetector)->detect($this->tempDir));
    }

    public function test_minimal_gemfile_yields_ruby_runtime_with_medium_confidence(): void
    {
        file_put_contents($this->tempDir.'/Gemfile', "source 'https://rubygems.org'\n");

        $result = (new RubyRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame('ruby', $result->runtime);
        $this->assertSame('ruby', $result->framework);
        $this->assertSame('medium', $result->confidence);
        $this->assertSame('bundle install', $result->buildCommand);
        $this->assertSame(3000, $result->appPort);
    }

    public function test_pins_version_from_tool_versions_first(): void
    {
        file_put_contents($this->tempDir.'/Gemfile', "ruby '3.0.0'\n");
        file_put_contents($this->tempDir.'/.tool-versions', "ruby 3.3.4\nnode 20\n");
        file_put_contents($this->tempDir.'/.ruby-version', "3.1.4\n");

        $result = (new RubyRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame('3.3.4', $result->version);
        $this->assertContains('.tool-versions', $result->detectedFiles);
    }

    public function test_falls_back_to_ruby_version_file(): void
    {
        file_put_contents($this->tempDir.'/Gemfile', '');
        file_put_contents($this->tempDir.'/.ruby-version', "3.2.2\n");

        $result = (new RubyRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame('3.2.2', $result->version);
    }

    public function test_strips_ruby_prefix_from_ruby_version(): void
    {
        file_put_contents($this->tempDir.'/Gemfile', '');
        file_put_contents($this->tempDir.'/.ruby-version', "ruby-3.2.2\n");

        $result = (new RubyRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame('3.2.2', $result->version);
    }

    public function test_falls_back_to_gemfile_ruby_line(): void
    {
        file_put_contents($this->tempDir.'/Gemfile', "ruby \"3.2.4\"\ngem 'rails'\n");

        $result = (new RubyRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame('3.2.4', $result->version);
    }

    public function test_detects_rails_from_gemfile(): void
    {
        file_put_contents(
            $this->tempDir.'/Gemfile',
            "source 'https://rubygems.org'\ngem 'rails', '~> 7.1'\n",
        );

        $result = (new RubyRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame('rails', $result->framework);
        $this->assertSame('high', $result->confidence);
        $this->assertSame(
            'bundle install && bundle exec rails assets:precompile',
            $result->buildCommand,
        );
    }

    public function test_detects_rails_from_application_rb_when_gemfile_silent(): void
    {
        file_put_contents($this->tempDir.'/Gemfile', '');
        mkdir($this->tempDir.'/config');
        file_put_contents($this->tempDir.'/config/application.rb', "module App\nend\n");

        $result = (new RubyRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame('rails', $result->framework);
        $this->assertContains('config/application.rb', $result->detectedFiles);
    }

    public function test_rails_uses_puma_config_when_present(): void
    {
        file_put_contents($this->tempDir.'/Gemfile', "gem 'rails'\n");
        mkdir($this->tempDir.'/config');
        file_put_contents($this->tempDir.'/config/puma.rb', '');

        $result = (new RubyRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame('bundle exec puma -C config/puma.rb', $result->startCommand);
    }

    public function test_rails_falls_back_to_rails_server_when_no_puma_config(): void
    {
        file_put_contents($this->tempDir.'/Gemfile', "gem 'rails'\n");

        $result = (new RubyRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertStringContainsString('rails server', $result->startCommand ?? '');
    }

    public function test_detects_sinatra(): void
    {
        file_put_contents($this->tempDir.'/Gemfile', "gem 'sinatra'\n");
        file_put_contents($this->tempDir.'/config.ru', "require './app'\nrun Sinatra::Application\n");

        $result = (new RubyRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame('sinatra', $result->framework);
        $this->assertStringContainsString('rackup', $result->startCommand ?? '');
    }

    public function test_suggests_sidekiq_worker_when_config_yml_present(): void
    {
        file_put_contents($this->tempDir.'/Gemfile', "gem 'rails'\ngem 'sidekiq'\n");
        mkdir($this->tempDir.'/config');
        file_put_contents($this->tempDir.'/config/sidekiq.yml', ":queues:\n  - default\n");

        $result = (new RubyRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertCount(1, $result->processes);
        $process = $result->processes[0];
        $this->assertSame(SiteProcess::TYPE_WORKER, $process->type);
        $this->assertSame('sidekiq', $process->name);
        $this->assertSame('bundle exec sidekiq -C config/sidekiq.yml', $process->command);
        $this->assertContains('config/sidekiq.yml', $result->detectedFiles);
    }

    public function test_suggests_sidekiq_worker_with_initializer_only(): void
    {
        file_put_contents($this->tempDir.'/Gemfile', "gem 'rails'\ngem 'sidekiq'\n");
        mkdir($this->tempDir.'/config/initializers', 0o755, true);
        file_put_contents($this->tempDir.'/config/initializers/sidekiq.rb', "Sidekiq.configure_server {}\n");

        $result = (new RubyRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertCount(1, $result->processes);
        $this->assertSame('bundle exec sidekiq', $result->processes[0]->command);
    }

    public function test_does_not_suggest_sidekiq_worker_when_only_gem_present(): void
    {
        file_put_contents($this->tempDir.'/Gemfile', "gem 'rails'\ngem 'sidekiq'\n");

        $result = (new RubyRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame([], $result->processes);
    }

    public function test_does_not_suggest_sidekiq_worker_when_only_config_present(): void
    {
        file_put_contents($this->tempDir.'/Gemfile', "gem 'rails'\n");
        mkdir($this->tempDir.'/config');
        file_put_contents($this->tempDir.'/config/sidekiq.yml', '');

        $result = (new RubyRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame([], $result->processes);
    }

    public function test_reasons_describe_each_inference(): void
    {
        file_put_contents(
            $this->tempDir.'/Gemfile',
            "source 'https://rubygems.org'\nruby '3.2.0'\ngem 'rails', '~> 7.1'\ngem 'sidekiq'\n",
        );
        mkdir($this->tempDir.'/config');
        file_put_contents($this->tempDir.'/config/sidekiq.yml', '');

        $result = (new RubyRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $combined = implode("\n", $result->reasons);
        $this->assertStringContainsString('Gemfile', $combined);
        $this->assertStringContainsString('rails', $combined);
        $this->assertStringContainsString('sidekiq', $combined);
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
