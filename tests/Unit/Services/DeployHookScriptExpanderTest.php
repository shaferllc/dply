<?php


namespace Tests\Unit\Services\DeployHookScriptExpanderTest;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Services\Sites\DeployHookScriptExpander;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('expands tokens from site', function () {
    $site = Site::factory()->create([
        'name' => 'My App',
        'git_branch' => 'develop',
        'deployment_environment' => 'staging',
        'php_version' => '8.3',
        'meta' => [
            'rails_runtime' => ['env' => 'production'],
        ],
    ]);
    SiteDomain::query()->create([
        'site_id' => $site->id,
        'hostname' => 'app.example.test',
        'is_primary' => true,
        'www_redirect' => false,
    ]);

    $site->refresh()->load('domains');
    $expander = app(DeployHookScriptExpander::class);
    $script = 'echo {SITE_NAME}|{SITE_DOMAIN}|{SITE_PATH}|{BRANCH}|{DEPLOY_ENV}|{PHP_VERSION}|{RAILS_ENV}';
    $out = $expander->expand($script, $site);

    $path = $site->effectiveRepositoryPath();
    $this->assertStringContainsString('My App', $out);
    $this->assertStringContainsString('app.example.test', $out);
    $this->assertStringContainsString($path, $out);
    $this->assertStringContainsString('develop', $out);
    $this->assertStringContainsString('staging', $out);
    $this->assertStringContainsString('8.3', $out);
    $this->assertStringContainsString('production', $out);
});