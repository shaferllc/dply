<?php

declare(strict_types=1);

namespace Tests\Feature\Actions\Servers;

use App\Actions\Servers\InstallRuntimeOnServer;
use App\Contracts\RemoteShell;
use App\Models\Server;
use App\Services\Servers\MiseInstallScriptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstallRuntimeOnServerTest extends TestCase
{
    use RefreshDatabase;

    public function test_installs_node_via_mise_use_global_and_records_default(): void
    {
        $server = Server::factory()->ready()->create([
            'ssh_user' => 'dply',
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
            'meta' => [],
        ]);
        $shell = new InstallRuntimeRecordingShell;

        $result = (new InstallRuntimeOnServer(new MiseInstallScriptBuilder))
            ->execute($server, 'node', '22.7.0', fn () => $shell);

        $this->assertTrue($result['installed']);
        $this->assertSame('node', $result['runtime']);
        $this->assertSame('22.7.0', $result['version']);

        $hadInstallCall = collect($shell->execCalls)
            ->contains(fn ($c) => str_contains($c, 'mise use --global node@22.7.0'));
        $this->assertTrue($hadInstallCall);

        $server->refresh();
        $this->assertSame(['node' => '22.7.0'], $server->meta['runtime_defaults']);
    }

    public function test_skips_php_runtime_silently(): void
    {
        $server = Server::factory()->ready()->create([
            'ssh_user' => 'dply',
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
            'meta' => [],
        ]);
        $shell = new InstallRuntimeRecordingShell;

        $result = (new InstallRuntimeOnServer(new MiseInstallScriptBuilder))
            ->execute($server, 'php', '8.4', fn () => $shell);

        $this->assertFalse($result['installed']);
        $this->assertSame([], $shell->execCalls);

        $server->refresh();
        $this->assertNull($server->meta['runtime_defaults'] ?? null);
    }

    public function test_skips_unknown_runtime_silently(): void
    {
        $server = Server::factory()->ready()->create([
            'ssh_user' => 'dply',
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
            'meta' => [],
        ]);
        $shell = new InstallRuntimeRecordingShell;

        $result = (new InstallRuntimeOnServer(new MiseInstallScriptBuilder))
            ->execute($server, 'erlang', '27', fn () => $shell);

        $this->assertFalse($result['installed']);
    }

    public function test_throws_when_server_not_ready(): void
    {
        $server = Server::factory()->create([
            'status' => Server::STATUS_PROVISIONING,
            'ssh_private_key' => null,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Server must be ready');

        (new InstallRuntimeOnServer(new MiseInstallScriptBuilder))
            ->execute($server, 'node', '22.7.0', fn () => new InstallRuntimeRecordingShell);
    }

    public function test_merges_runtime_default_with_existing_entries(): void
    {
        $server = Server::factory()->ready()->create([
            'ssh_user' => 'dply',
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
            'meta' => [
                'runtime_defaults' => ['node' => '22'],
            ],
        ]);
        $shell = new InstallRuntimeRecordingShell;

        (new InstallRuntimeOnServer(new MiseInstallScriptBuilder))
            ->execute($server, 'python', '3.12', fn () => $shell);

        $server->refresh();
        $this->assertSame(
            ['node' => '22', 'python' => '3.12'],
            $server->meta['runtime_defaults'],
        );
    }
}

class InstallRuntimeRecordingShell implements RemoteShell
{
    /** @var list<string> */
    public array $execCalls = [];

    public function exec(string $command, int $timeoutSeconds = 120): string
    {
        $this->execCalls[] = $command;

        return '';
    }

    public function putFile(string $remotePath, string $contents, int $timeoutSeconds = 60): void {}
}
