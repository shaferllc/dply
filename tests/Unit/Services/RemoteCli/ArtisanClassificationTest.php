<?php

declare(strict_types=1);

namespace Tests\Unit\Services\RemoteCli;

use App\Services\RemoteCli\Artisan;
use App\Services\RemoteCli\Kind;
use App\Services\RemoteCli\RemoteCliPermissions;
use App\Services\RemoteCli\RiskLevel;
use App\Services\RemoteCli\SiteAuditWriter;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Symmetric to {@see WpCliClassificationTest} — pure-unit checks
 * against the Artisan classification + allowlist tables.
 */
class ArtisanClassificationTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function artisan(): Artisan
    {
        return new Artisan(
            Mockery::mock(ExecuteRemoteTaskOnServer::class),
            new RemoteCliPermissions(),
            new SiteAuditWriter(),
        );
    }

    public function test_kind_is_artisan(): void
    {
        $this->assertSame(Kind::Artisan, $this->artisan()->kind());
    }

    #[DataProvider('readCommandProvider')]
    public function test_known_read_commands_classify_as_read(string $command): void
    {
        $this->assertSame(
            RiskLevel::Read,
            $this->artisan()->classifyRisk($command),
            "Expected {$command} to classify as Read",
        );
    }

    public static function readCommandProvider(): array
    {
        return [
            ['route:list'],
            ['config:show database'],
            ['env'],
            ['queue:size'],
            ['queue:failed'],
            ['schedule:list'],
            ['migrate:status'],
            ['about'],
            ['pail'],
        ];
    }

    #[DataProvider('mutatingRecoverableProvider')]
    public function test_known_recoverable_commands_classify_correctly(string $command): void
    {
        $this->assertSame(
            RiskLevel::MutatingRecoverable,
            $this->artisan()->classifyRisk($command),
            "Expected {$command} to classify as MutatingRecoverable",
        );
    }

    public static function mutatingRecoverableProvider(): array
    {
        return [
            ['migrate'],
            ['migrate:install'],
            ['queue:work --once'],
            ['queue:retry all'],
            ['schedule:run'],
            ['make:controller PostController'],
            ['make:model Post -mfsc'],
            ['vendor:publish --tag=horizon-config'],
        ];
    }

    #[DataProvider('destructiveProvider')]
    public function test_destructive_and_unknown_commands_classify_as_destructive(string $command): void
    {
        $this->assertSame(
            RiskLevel::Destructive,
            $this->artisan()->classifyRisk($command),
            "Expected {$command} to classify as Destructive (failsafe)",
        );
    }

    public static function destructiveProvider(): array
    {
        return [
            ['migrate:rollback'],
            ['migrate:reset'],
            ['migrate:fresh --seed'], // wipes
            ['migrate:wipe --force'],
            ['db:seed'],
            ['db:wipe'],
            ['tinker'], // runtime input, can't statically classify
            ['queue:clear redis'],
            ['key:generate --force'], // rotates secrets, breaks sessions
            ['model:prune'],
            ['totally-made-up-artisan-command'], // failsafe for unknowns
        ];
    }

    #[DataProvider('instantCommandProvider')]
    public function test_instant_commands_are_recognised(string $command, bool $expected): void
    {
        $this->assertSame(
            $expected,
            $this->artisan()->isInstant($command),
            "Expected isInstant({$command}) to return ".($expected ? 'true' : 'false'),
        );
    }

    public static function instantCommandProvider(): array
    {
        return [
            ['route:list', true],
            ['route:list --json', true],
            ['migrate:status', true],
            ['schedule:list', true],
            ['migrate', false],
            ['migrate:rollback', false],
            ['totally-made-up', false],
        ];
    }
}
