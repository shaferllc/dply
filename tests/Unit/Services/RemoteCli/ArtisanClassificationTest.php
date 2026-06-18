<?php

declare(strict_types=1);

namespace Tests\Unit\Services\RemoteCli\ArtisanClassificationTest;

use App\Modules\RemoteCli\Services\Artisan;
use App\Modules\RemoteCli\Services\Kind;
use App\Modules\RemoteCli\Services\RemoteCliPermissions;
use App\Modules\RemoteCli\Services\RiskLevel;
use App\Modules\RemoteCli\Services\SiteAuditWriter;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Mockery;

afterEach(function () {
    Mockery::close();
});
function artisan(): Artisan
{
    return new Artisan(
        Mockery::mock(ExecuteRemoteTaskOnServer::class),
        new RemoteCliPermissions,
        new SiteAuditWriter,
    );
}
test('kind is artisan', function () {
    expect(artisan()->kind())->toBe(Kind::Artisan);
});
test('known read commands classify as read', function (string $command) {
    expect(artisan()->classifyRisk($command))->toBe(RiskLevel::Read, "Expected {$command} to classify as Read");
})->with('readCommandProvider');
dataset('readCommandProvider', function () {
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
});
test('known recoverable commands classify correctly', function (string $command) {
    expect(artisan()->classifyRisk($command))->toBe(RiskLevel::MutatingRecoverable, "Expected {$command} to classify as MutatingRecoverable");
})->with('mutatingRecoverableProvider');
dataset('mutatingRecoverableProvider', function () {
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
});
test('destructive and unknown commands classify as destructive', function (string $command) {
    expect(artisan()->classifyRisk($command))->toBe(RiskLevel::Destructive, "Expected {$command} to classify as Destructive (failsafe)");
})->with('destructiveProvider');
dataset('destructiveProvider', function () {
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
});
test('instant commands are recognised', function (string $command, bool $expected) {
    expect(artisan()->isInstant($command))->toBe($expected, "Expected isInstant({$command}) to return ".($expected ? 'true' : 'false'));
})->with('instantCommandProvider');
dataset('instantCommandProvider', function () {
    return [
        ['route:list', true],
        ['route:list --json', true],
        ['migrate:status', true],
        ['schedule:list', true],
        ['migrate', false],
        ['migrate:rollback', false],
        ['totally-made-up', false],
    ];
});
