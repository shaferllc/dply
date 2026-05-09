<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Site;
use App\Services\Sites\DotEnvFileParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SetSiteEnvCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_sets_a_new_environment_variable(): void
    {
        $site = $this->makeSite();

        $exit = Artisan::call('dply:site:env-set', [
            'site' => $site->slug,
            'assignment' => 'API_KEY=secret',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Set API_KEY', $output);

        $vars = $this->parsed($site->fresh());
        $this->assertSame('secret', $vars['API_KEY'] ?? null);
        $this->assertSame('local-edit', $site->fresh()->env_cache_origin);
    }

    public function test_command_updates_existing_variable_in_place(): void
    {
        $site = $this->makeSite(['env_file_content' => 'API_KEY=old']);

        Artisan::call('dply:site:env-set', [
            'site' => $site->slug,
            'assignment' => 'API_KEY=new',
        ]);

        $vars = $this->parsed($site->fresh());
        $this->assertSame(['API_KEY' => 'new'], $vars);
    }

    public function test_unset_flag_removes_variable(): void
    {
        $site = $this->makeSite(['env_file_content' => 'API_KEY=something']);

        $exit = Artisan::call('dply:site:env-set', [
            'site' => $site->slug,
            'assignment' => 'API_KEY=',
            '--unset' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame([], $this->parsed($site->fresh()));
    }

    public function test_unset_is_a_noop_when_variable_was_not_set(): void
    {
        $site = $this->makeSite();

        $exit = Artisan::call('dply:site:env-set', [
            'site' => $site->slug,
            'assignment' => 'API_KEY=',
            '--unset' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('was not set', $output);
    }

    public function test_command_preserves_other_keys_when_setting_one(): void
    {
        $site = $this->makeSite(['env_file_content' => "FOO=one\nBAR=two"]);

        Artisan::call('dply:site:env-set', [
            'site' => $site->slug,
            'assignment' => 'BAZ=three',
        ]);

        $vars = $this->parsed($site->fresh());
        $this->assertSame(['BAR' => 'two', 'BAZ' => 'three', 'FOO' => 'one'], $vars);
    }

    public function test_command_rejects_invalid_assignment_format(): void
    {
        $site = $this->makeSite();

        $exit = Artisan::call('dply:site:env-set', [
            'site' => $site->slug,
            'assignment' => 'no-equal-sign',
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('KEY=VALUE', $output);
    }

    public function test_command_rejects_invalid_key_pattern(): void
    {
        $site = $this->makeSite();

        $exit = Artisan::call('dply:site:env-set', [
            'site' => $site->slug,
            'assignment' => 'lowercase-key=foo',
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('KEY must match', $output);
    }

    public function test_command_fails_when_site_not_found(): void
    {
        $exit = Artisan::call('dply:site:env-set', [
            'site' => 'nope',
            'assignment' => 'X=y',
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Site not found', $output);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function makeSite(array $attrs = []): Site
    {
        $server = Server::factory()->create();

        return Site::factory()->create(array_merge([
            'server_id' => $server->id,
            'slug' => 'jobs',
        ], $attrs));
    }

    /**
     * @return array<string, string>
     */
    private function parsed(Site $site): array
    {
        $vars = app(DotEnvFileParser::class)->parse((string) ($site->env_file_content ?? ''))['variables'];
        ksort($vars);

        return $vars;
    }
}
