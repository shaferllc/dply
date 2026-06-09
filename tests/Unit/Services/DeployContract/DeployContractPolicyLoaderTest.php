<?php

declare(strict_types=1);

use App\Services\DeployContract\DeployContractPolicy;
use App\Services\DeployContract\DeployContractPolicyLoader;

test('loads standalone dply-contract.yaml', function () {
    $dir = sys_get_temp_dir().'/dply-contract-'.uniqid('', true);
    mkdir($dir);
    file_put_contents($dir.'/dply-contract.yaml', <<<'YAML'
promote:
  requires:
    - edge.preview.replay
  min_replay_pass_rate: 95
YAML);

    $loaded = app(DeployContractPolicyLoader::class)->loadFromDirectory($dir);
    $policy = DeployContractPolicy::fromRepoConfig($loaded);

    expect($loaded)->not->toBeNull()
        ->and($policy->requires)->toContain('edge.preview.replay')
        ->and($policy->effectiveMinReplayPassRate())->toBe(95.0);

    @unlink($dir.'/dply-contract.yaml');
    @rmdir($dir);
});

test('loads contract block from dply.yaml', function () {
    $dir = sys_get_temp_dir().'/dply-yaml-contract-'.uniqid('', true);
    mkdir($dir);
    file_put_contents($dir.'/dply.yaml', <<<'YAML'
build:
  command: npm run build
contract:
  promote:
    requires:
      - cloud.origin.health
YAML);

    $loaded = app(DeployContractPolicyLoader::class)->loadFromDirectory($dir);
    $policy = DeployContractPolicy::fromRepoConfig($loaded);

    expect($policy->requires)->toBe(['cloud.origin.health']);

    @unlink($dir.'/dply.yaml');
    @rmdir($dir);
});
