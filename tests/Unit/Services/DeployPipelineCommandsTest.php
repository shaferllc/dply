<?php

declare(strict_types=1);

namespace Tests\Unit\Services\DeployPipelineCommandsTest;

use App\Models\SiteDeployStep;
use App\Services\Sites\SiteDeployPipelineCommands;

/**
 * mise installs the Node runtime, not the alternate package managers, so a
 * pnpm/yarn repo hit `pnpm: command not found` (exit 127) on a box that only
 * had Node. Corepack ships with Node and can run the pinned manager on demand.
 */
test('pnpm falls back to corepack when the binary is missing', function () {
    $cmd = SiteDeployPipelineCommands::fragmentFor(SiteDeployStep::TYPE_PNPM_INSTALL);

    expect($cmd)->toContain('command -v pnpm')
        ->and($cmd)->toContain('corepack pnpm install --frozen-lockfile')
        ->and($cmd)->toContain('pnpm install --frozen-lockfile');
});

test('yarn gets the same fallback', function () {
    $cmd = SiteDeployPipelineCommands::fragmentFor(SiteDeployStep::TYPE_YARN_INSTALL);

    expect($cmd)->toContain('corepack yarn install --frozen-lockfile');
});

test('bun does not use corepack', function () {
    // Bun is a runtime in its own right, not a corepack-managed package manager.
    expect(SiteDeployPipelineCommands::fragmentFor(SiteDeployStep::TYPE_BUN_INSTALL))
        ->toBe('bun install --frozen-lockfile');
});

test('npm is untouched', function () {
    expect(SiteDeployPipelineCommands::fragmentFor(SiteDeployStep::TYPE_NPM_CI))
        ->toBe('npm ci --no-audit --no-fund');
});
