<?php

declare(strict_types=1);

namespace Tests\Unit\Services\RemoteCli;

use App\Services\RemoteCli\Kind;
use App\Services\RemoteCli\RemoteCliPermissions;
use App\Services\RemoteCli\RiskLevel;
use App\Services\RemoteCli\SiteAuditWriter;
use App\Services\RemoteCli\WpCli;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pure-unit checks against the static classification + allowlist tables
 * on WpCli — no DB, no Site fixture. The tables are the contract used
 * by every wp-cli surface; if a command is misclassified here, the
 * permission gate in PR 2 grants the wrong access.
 */
class WpCliClassificationTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function wpcli(): WpCli
    {
        return new WpCli(
            Mockery::mock(ExecuteRemoteTaskOnServer::class),
            new RemoteCliPermissions,
            new SiteAuditWriter,
        );
    }

    public function test_kind_is_wp(): void
    {
        $this->assertSame(Kind::Wp, $this->wpcli()->kind());
    }

    #[DataProvider('readCommandProvider')]
    public function test_known_read_commands_classify_as_read(string $command): void
    {
        $this->assertSame(
            RiskLevel::Read,
            $this->wpcli()->classifyRisk($command),
            "Expected {$command} to classify as Read",
        );
    }

    public static function readCommandProvider(): array
    {
        return [
            ['option get siteurl'],
            ['plugin list --format=json'],
            ['theme list'],
            ['cron event list'],
            ['core version'],
            ['db check'],
            ['user list --role=administrator'],
            ['config get DB_NAME'],
        ];
    }

    #[DataProvider('mutatingRecoverableProvider')]
    public function test_known_recoverable_commands_classify_correctly(string $command): void
    {
        $this->assertSame(
            RiskLevel::MutatingRecoverable,
            $this->wpcli()->classifyRisk($command),
            "Expected {$command} to classify as MutatingRecoverable",
        );
    }

    public static function mutatingRecoverableProvider(): array
    {
        return [
            ['plugin install woocommerce'],
            ['plugin update --all'],
            ['plugin activate akismet'],
            ['theme install twentytwentyfive'],
            ['core update'],
            ['cron event run wp_version_check'],
            ['user create alice alice@example.com --role=author'],
            ['cache flush'],
            ['rewrite flush'],
        ];
    }

    #[DataProvider('destructiveProvider')]
    public function test_destructive_and_unknown_commands_classify_as_destructive(string $command): void
    {
        $this->assertSame(
            RiskLevel::Destructive,
            $this->wpcli()->classifyRisk($command),
            "Expected {$command} to classify as Destructive (failsafe)",
        );
    }

    public static function destructiveProvider(): array
    {
        return [
            ['db drop'],
            ['db reset --yes'],
            ['db import dump.sql'],
            ['plugin delete akismet'],
            ['theme delete twentytwentythree'],
            ['user delete 5 --reassign=1'],
            ['option delete siteurl'],
            ['eval "echo 1;"'],
            ['eval-file /tmp/script.php'],
            ['search-replace https://old.com https://new.com --all-tables'],
            ['totally-made-up-command'], // failsafe for unknowns
        ];
    }

    #[DataProvider('instantCommandProvider')]
    public function test_instant_commands_are_recognised(string $command, bool $expected): void
    {
        $this->assertSame(
            $expected,
            $this->wpcli()->isInstant($command),
            "Expected isInstant({$command}) to return ".($expected ? 'true' : 'false'),
        );
    }

    public static function instantCommandProvider(): array
    {
        return [
            ['plugin list', true],
            ['plugin list --format=json', true], // matches via prefix-with-arg
            ['option get siteurl', true],
            ['core version', true],
            ['plugin install woocommerce', false], // mutating, not instant
            ['db drop', false], // destructive, not instant
            ['totally-made-up', false], // unknown, not instant
        ];
    }
}
