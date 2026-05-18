<?php

/**
 * Console catalog — Custom site section.
 *
 * Shown only on Custom (headless) sites. Placeholders:
 *   {deploy_path} — absolute path to the site's working directory
 *   {site_name}   — the Site::$name attribute
 */
return [
    'label' => 'Custom site',
    'description' => 'Inspect and run a Custom (headless) site\'s deploy directory.',
    'requires_any_tags' => ['site:custom'],
    'entries' => [
        ['command' => 'cd {deploy_path} && pwd', 'description' => 'Resolve the site working directory.'],
        ['command' => 'cd {deploy_path} && ls -lah', 'description' => 'List files in the site working directory.'],
        ['command' => 'cd {deploy_path} && git status --short', 'description' => 'Show git status (git-mode only).', 'requires_any_tags' => ['site:custom:git']],
        ['command' => 'cd {deploy_path} && git log --oneline -n 10', 'description' => 'Recent commits (git-mode only).', 'requires_any_tags' => ['site:custom:git']],
        ['command' => 'tail -n 200 {deploy_path}/.dply/deploy.log 2>/dev/null || echo "no deploy log yet"', 'description' => 'Tail the most recent deploy log.'],
        ['command' => 'cd {deploy_path} && du -sh . 2>/dev/null', 'description' => 'Total size of the site directory.'],
    ],
];
