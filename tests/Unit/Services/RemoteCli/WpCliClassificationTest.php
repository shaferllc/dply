<?php

declare(strict_types=1);

namespace Tests\Unit\Services\RemoteCli\WpCliClassificationTest;
use Mockery;

use App\Services\RemoteCli\Kind;
use App\Services\RemoteCli\RemoteCliPermissions;
use App\Services\RemoteCli\RiskLevel;
use App\Services\RemoteCli\SiteAuditWriter;
use App\Services\RemoteCli\WpCli;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use PHPUnit\Framework\Attributes\DataProvider;
afterEach(function () {
    Mockery::close();
});
function wpcli(): WpCli
{
    return new WpCli(
        Mockery::mock(ExecuteRemoteTaskOnServer::class),
        new RemoteCliPermissions,
        new SiteAuditWriter,
    );
}
test('kind is wp', function () {
    expect(wpcli()->kind())->toBe(Kind::Wp);
});
test('known read commands classify as read', function (string $command) {
    expect(wpcli()->classifyRisk($command))->toBe(RiskLevel::Read, "Expected {$command} to classify as Read");
})->with('readCommandProvider');
dataset('readCommandProvider', function () {
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
});
test('known recoverable commands classify correctly', function (string $command) {
    expect(wpcli()->classifyRisk($command))->toBe(RiskLevel::MutatingRecoverable, "Expected {$command} to classify as MutatingRecoverable");
})->with('mutatingRecoverableProvider');
dataset('mutatingRecoverableProvider', function () {
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
});
test('destructive and unknown commands classify as destructive', function (string $command) {
    expect(wpcli()->classifyRisk($command))->toBe(RiskLevel::Destructive, "Expected {$command} to classify as Destructive (failsafe)");
})->with('destructiveProvider');
dataset('destructiveProvider', function () {
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
});
test('instant commands are recognised', function (string $command, bool $expected) {
    expect(wpcli()->isInstant($command))->toBe($expected, "Expected isInstant({$command}) to return ".($expected ? 'true' : 'false'));
})->with('instantCommandProvider');
dataset('instantCommandProvider', function () {
    return [
        ['plugin list', true],
        ['plugin list --format=json', true], // matches via prefix-with-arg
        ['option get siteurl', true],
        ['core version', true],
        ['plugin install woocommerce', false], // mutating, not instant
        ['db drop', false], // destructive, not instant
        ['totally-made-up', false], // unknown, not instant
    ];
});
