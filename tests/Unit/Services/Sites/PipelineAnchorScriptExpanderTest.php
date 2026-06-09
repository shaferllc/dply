<?php

declare(strict_types=1);

use App\Models\Site;
use App\Services\Sites\PipelineAnchorScriptExpander;

test('pipeline anchor script expander substitutes release and repo tokens', function () {
    $site = Site::factory()->make([
        'git_repository_url' => 'git@github.com:acme/app.git',
        'git_branch' => 'develop',
        'deploy_strategy' => 'atomic',
    ]);

    $expander = app(PipelineAnchorScriptExpander::class);
    $out = $expander->expand(
        'cd {RELEASE_DIR} && {GIT_SSH_PREFIX}git clone {REPO_URL} .',
        $site,
        '/var/www/app/releases/20260101120000',
        'export GIT_SSH_COMMAND=foo && ',
        'git@github.com:acme/app.git',
        'develop',
    );

    expect($out)
        ->toContain('/var/www/app/releases/20260101120000')
        ->toContain('export GIT_SSH_COMMAND=foo && ')
        ->toContain('git@github.com:acme/app.git');
});
