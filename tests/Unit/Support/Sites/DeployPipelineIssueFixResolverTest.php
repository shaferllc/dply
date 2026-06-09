<?php

declare(strict_types=1);

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployStep;
use App\Support\Sites\DeployPipelineIssueFixResolver;

test('pipeline fix resolver maps simple deploy migrations to an in-place action', function (): void {
    $server = Server::factory()->make();
    $server->id = '01HZYPIPEFIXSERVER001';

    $site = Site::factory()->make(['server_id' => $server->id]);
    $site->id = '01HZYPIPEFIXSITE00001';
    $site->setRelation('server', $server);

    $fix = DeployPipelineIssueFixResolver::fixFor($site, $server, 'simple_deploy_migrations');

    expect($fix)->not->toBeNull()
        ->and($fix['label'])->toBe(__('Enable zero downtime'))
        ->and($fix['action'] ?? null)->toBe('enableZeroDowntimeDeploys')
        ->and($fix['url'] ?? null)->toBeNull();
});

test('pipeline fix resolver maps migrate without backup to server databases', function (): void {
    $server = Server::factory()->make();
    $server->id = '01HZYPIPEFIXSERVER002';

    $site = Site::factory()->make(['server_id' => $server->id]);
    $site->id = '01HZYPIPEFIXSITE00002';
    $site->setRelation('server', $server);

    $fix = DeployPipelineIssueFixResolver::fixFor($site, $server, 'migrate_without_backup');

    expect($fix)->not->toBeNull()
        ->and($fix['label'])->toBe(__('Open database backups'))
        ->and($fix['url'])->toContain('/databases');
});

test('pipeline fix resolver maps hook errors to edit hooks on steps tab', function (): void {
    $server = Server::factory()->make();
    $server->id = '01HZYPIPEFIXSERVER003';

    $site = Site::factory()->make(['server_id' => $server->id]);
    $site->id = '01HZYPIPEFIXSITE00003';
    $site->setRelation('server', $server);

    $fix = DeployPipelineIssueFixResolver::fixFor($site, $server, 'empty_shell_hook_abc123');

    expect($fix)->not->toBeNull()
        ->and($fix['label'])->toBe(__('Edit hooks'))
        ->and($fix['url'])->toContain('tab=steps');
});

test('pipeline fix resolver maps release step in build to an in-place move action', function (): void {
    $server = Server::factory()->make();
    $server->id = '01HZYPIPEFIXSERVER004';

    $site = Site::factory()->make(['server_id' => $server->id]);
    $site->id = '01HZYPIPEFIXSITE00004';
    $site->setRelation('server', $server);

    $fix = DeployPipelineIssueFixResolver::fixFor(
        $site,
        $server,
        'release_step_in_build_'.SiteDeployStep::TYPE_ARTISAN_MIGRATE,
        [
            'step_type' => SiteDeployStep::TYPE_ARTISAN_MIGRATE,
            'from_phase' => SiteDeployStep::PHASE_BUILD,
            'to_phase' => SiteDeployStep::PHASE_RELEASE,
        ],
    );

    expect($fix)->not->toBeNull()
        ->and($fix['label'])->toBe(__('Move to release'))
        ->and($fix['action'] ?? null)->toBe(
            sprintf("moveDeployStepsToPhase('%s', '%s', '%s')", SiteDeployStep::TYPE_ARTISAN_MIGRATE, SiteDeployStep::PHASE_BUILD, SiteDeployStep::PHASE_RELEASE),
        );
});

test('pipeline fix resolver falls back to steps tab when phase move lacks metadata', function (): void {
    $server = Server::factory()->make();
    $server->id = '01HZYPIPEFIXSERVER006';

    $site = Site::factory()->make(['server_id' => $server->id]);
    $site->id = '01HZYPIPEFIXSITE00006';
    $site->setRelation('server', $server);

    $fix = DeployPipelineIssueFixResolver::fixFor(
        $site,
        $server,
        'release_step_in_build_'.SiteDeployStep::TYPE_ARTISAN_MIGRATE,
    );

    expect($fix)->not->toBeNull()
        ->and($fix['label'])->toBe(__('Move to release'))
        ->and($fix['url'] ?? null)->toContain('tab=steps');
});

test('pipeline fix resolver maps duplicate step to an in-place remove action', function (): void {
    $server = Server::factory()->make();
    $server->id = '01HZYPIPEFIXSERVER007';

    $site = Site::factory()->make(['server_id' => $server->id]);
    $site->id = '01HZYPIPEFIXSITE00007';
    $site->setRelation('server', $server);

    $fix = DeployPipelineIssueFixResolver::fixFor(
        $site,
        $server,
        'duplicate_step_'.SiteDeployStep::TYPE_ARTISAN_MIGRATE.'_Release',
        [
            'step_type' => SiteDeployStep::TYPE_ARTISAN_MIGRATE,
            'phase' => SiteDeployStep::PHASE_RELEASE,
        ],
    );

    expect($fix)->not->toBeNull()
        ->and($fix['label'])->toBe(__('Remove duplicate'))
        ->and($fix['action'] ?? null)->toBe(
            sprintf("removeDuplicateDeployStep('%s', '%s')", SiteDeployStep::TYPE_ARTISAN_MIGRATE, SiteDeployStep::PHASE_RELEASE),
        );
});

test('pipeline actionable checks include contextual fix links', function (): void {
    $server = Server::factory()->make();
    $server->id = '01HZYPIPEFIXSERVER005';

    $site = Site::factory()->make(['server_id' => $server->id]);
    $site->id = '01HZYPIPEFIXSITE00005';
    $site->setRelation('server', $server);

    $checks = DeployPipelineIssueFixResolver::actionableChecks($site, $server, collect([
        ['key' => 'simple_deploy_migrations', 'level' => 'warning', 'message' => 'Migrations on simple deploy.'],
        ['key' => 'empty_pipeline', 'level' => 'ok', 'message' => 'Ignored.'],
    ]));

    expect($checks)->toHaveCount(1)
        ->and($checks->first()['fix']['label'] ?? null)->toBe(__('Enable zero downtime'));
});
