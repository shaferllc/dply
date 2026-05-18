<?php

/**
 * Console catalog — PHP section.
 *
 * Shown when the server has PHP installed (php-fpm in expected_services, or
 * stack_summary.php_version set). The version placeholder `{php_version}` is
 * substituted at load time by ConsoleCatalog from ServerInstalledServices.
 */
return [
    'label' => 'PHP',
    'description' => 'PHP runtime, FPM pool, and Composer.',
    'requires_any_tags' => ['php'],
    'entries' => [
        ['command' => 'php -v', 'description' => 'PHP version + loaded extensions summary.'],
        ['command' => 'php --ini', 'description' => 'Loaded php.ini paths.'],
        ['command' => 'php -m', 'description' => 'List loaded PHP modules.'],
        ['command' => 'php -i | grep -iE "memory_limit|max_execution|upload_max|post_max"', 'description' => 'Common runtime limits.'],
        ['command' => 'php-fpm{php_version} -t', 'description' => 'Test FPM config syntax.'],
        ['command' => 'systemctl status php{php_version}-fpm --no-pager -n 20', 'description' => 'FPM pool status.'],
        ['command' => 'systemctl reload php{php_version}-fpm', 'description' => 'Reload FPM after config changes.'],
        ['command' => 'tail -n 200 /var/log/php{php_version}-fpm.log', 'description' => 'FPM master log.'],
        ['command' => 'composer --version', 'description' => 'Composer version.'],
        ['command' => 'composer self-update', 'description' => 'Update Composer to the latest release.'],
    ],
];
