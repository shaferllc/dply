<?php

namespace Tests\Unit\Services;

use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Services\Servers\ServerPhpConfigEditor;
use App\Services\Servers\ServerPhpConfigValidationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServerPhpConfigEditorTest extends TestCase
{
    use RefreshDatabase;

    protected function makeServerWithMeta(array $meta = []): Server
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create();
        $user->organizations()->attach($org->id, ['role' => 'owner']);

        return Server::factory()->create([
            'organization_id' => $org->id,
            'meta' => array_merge([
                'server_role' => 'application',
                'php_inventory' => [
                    'supported' => true,
                    'installed_versions' => ['8.3'],
                    'detected_default_version' => '8.3',
                ],
            ], $meta),
            'ip_address' => '203.0.113.10',
            'ssh_user' => 'root',
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
            'status' => Server::STATUS_READY,
            'setup_status' => Server::SETUP_STATUS_DONE,
        ]);
    }

    #[Test]
    public function it_resolves_the_expected_paths_and_labels_for_each_supported_target(): void
    {
        $server = $this->makeServerWithMeta();
        $editor = new ServerPhpConfigEditor;

        $cliTarget = $editor->resolveEditableTarget($server, '8.3', 'cli_ini');
        $fpmTarget = $editor->resolveEditableTarget($server, '8.3', 'fpm_ini');
        $poolTarget = $editor->resolveEditableTarget($server, '8.3', 'pool_config');

        $this->assertSame('CLI ini', $cliTarget['label']);
        $this->assertSame('/etc/php/8.3/cli/php.ini', $cliTarget['path']);
        $this->assertSame('FPM ini', $fpmTarget['label']);
        $this->assertSame('/etc/php/8.3/fpm/php.ini', $fpmTarget['path']);
        $this->assertSame('Pool config', $poolTarget['label']);
        $this->assertSame('/etc/php/8.3/fpm/pool.d/www.conf', $poolTarget['path']);
    }

    #[Test]
    public function it_reads_the_current_content_for_a_resolved_target(): void
    {
        $server = $this->makeServerWithMeta();

        $editor = Mockery::mock(ServerPhpConfigEditor::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $editor->shouldReceive('readRemoteTarget')
            ->once()
            ->withArgs(fn (Server $refreshedServer, array $target) => $refreshedServer->is($server) && $target['path'] === '/etc/php/8.3/cli/php.ini')
            ->andReturn("memory_limit=512M\n");

        $result = $editor->openTarget($server, '8.3', 'cli_ini');

        $this->assertSame('CLI ini', $result['label']);
        $this->assertSame('/etc/php/8.3/cli/php.ini', $result['path']);
        $this->assertSame("memory_limit=512M\n", $result['content']);
    }

    #[Test]
    public function it_rejects_validation_failures_before_the_live_file_is_replaced(): void
    {
        $server = $this->makeServerWithMeta();

        $editor = Mockery::mock(ServerPhpConfigEditor::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $editor->shouldReceive('verifyProposedContent')
            ->once()
            ->andThrow(new ServerPhpConfigValidationException(
                'CLI ini validation failed. The live file was not replaced.',
                'PHP:  syntax error, unexpected "=" on line 2'
            ));
        $editor->shouldNotReceive('replaceRemoteTarget');

        $this->expectException(ServerPhpConfigValidationException::class);
        $this->expectExceptionMessage('CLI ini validation failed. The live file was not replaced.');

        $editor->saveTarget($server, '8.3', 'cli_ini', "memory_limit==512M\n");
    }

    #[Test]
    public function it_fails_clearly_for_unsupported_target_types(): void
    {
        $server = $this->makeServerWithMeta();
        $editor = new ServerPhpConfigEditor;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown PHP config target.');

        $editor->resolveEditableTarget($server, '8.3', 'apache_ini');
    }

    #[Test]
    public function it_fails_clearly_when_the_expected_target_is_missing_on_the_server(): void
    {
        $server = $this->makeServerWithMeta();

        $editor = Mockery::mock(ServerPhpConfigEditor::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $editor->shouldReceive('readRemoteTarget')
            ->once()
            ->andThrow(new \RuntimeException('Pool config is not available for PHP 8.3 on this server.'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Pool config is not available for PHP 8.3 on this server.');

        $editor->openTarget($server, '8.3', 'pool_config');
    }

    #[Test]
    public function it_returns_reload_guidance_after_a_verified_write_succeeds(): void
    {
        $server = $this->makeServerWithMeta();

        $editor = Mockery::mock(ServerPhpConfigEditor::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $editor->shouldReceive('verifyProposedContent')
            ->once()
            ->andReturn([
                'output' => 'configuration file syntax is ok',
            ]);
        $editor->shouldReceive('replaceRemoteTarget')
            ->once();

        $result = $editor->saveTarget($server, '8.3', 'fpm_ini', "memory_limit=512M\n");

        $this->assertSame('FPM ini saved for PHP 8.3.', $result['message']);
        $this->assertStringContainsString('Reload PHP-FPM 8.3', $result['reload_guidance']);
        $this->assertSame('configuration file syntax is ok', $result['verification_output']);
    }

    #[Test]
    public function it_rejects_config_edits_while_another_server_level_php_mutation_is_running(): void
    {
        $server = $this->makeServerWithMeta();
        $editor = new ServerPhpConfigEditor;
        $lock = Cache::lock('server-php-package-action:'.$server->id, 150);

        $this->assertTrue($lock->get());

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Another PHP server mutation is already running for this server.');

            $editor->saveTarget($server, '8.3', 'cli_ini', "memory_limit=512M\n");
        } finally {
            $lock->release();
        }
    }
}
