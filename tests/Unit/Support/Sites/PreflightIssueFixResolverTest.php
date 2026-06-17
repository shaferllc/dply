<?php

declare(strict_types=1);

use App\Models\Server;
use App\Models\Site;
use App\Support\Sites\PreflightIssueFixResolver;

test('preflight fix resolver maps publication warnings to routing', function (): void {
    $server = Server::factory()->make();
    $server->id = '01HZYPREFLIGHTSERVER01';

    $site = Site::factory()->make(['server_id' => $server->id]);
    $site->id = '01HZYPREFLIGHTSITE0001';
    $site->setRelation('server', $server);

    $fix = PreflightIssueFixResolver::fixFor($site, $server, 'publication');

    expect($fix)->not->toBeNull()
        ->and($fix['label'])->toBe(__('Open routing'))
        ->and($fix['url'])->toContain('/routing');
});

test('preflight actionable checks include fix links for warnings', function (): void {
    $server = Server::factory()->make();
    $server->id = '01HZYPREFLIGHTSERVER02';

    $site = Site::factory()->make(['server_id' => $server->id]);
    $site->id = '01HZYPREFLIGHTSITE0002';
    $site->setRelation('server', $server);

    $checks = PreflightIssueFixResolver::actionableChecks($site, $server, collect([
        ['key' => 'redis', 'level' => 'warning', 'message' => 'Redis env is missing.'],
        ['key' => 'server', 'level' => 'ok', 'message' => 'Server attached.'],
    ]));

    expect($checks)->toHaveCount(1)
        ->and($checks[0]['fix']['label'] ?? null)->toBe(__('Open environment'));
});
