<?php

declare(strict_types=1);

use App\Models\Site;
use App\Support\Sites\PhpVersionUpgradePlanner;

test('composer php mismatch output yields the required catalog version', function () {
    $output = <<<'LOG'
    - symfony/translation v8.1.4 requires php >=8.4.1 -> your php version (8.3.6) does not satisfy that requirement.
    - nesbot/carbon v3.11.1 requires php >=8.4.1 -> your php version (8.3.6) does not satisfy that requirement.
    LOG;

    expect(PhpVersionUpgradePlanner::requiredFromOutput($output))->toBe('8.4.1')
        ->and(PhpVersionUpgradePlanner::currentFromOutput($output))->toBe('8.3')
        ->and(PhpVersionUpgradePlanner::catalogVersionFor('8.4.1'))->toBe('8.4');
});

test('constraint parsing covers composer operators', function (string $constraint, string $expected) {
    expect(PhpVersionUpgradePlanner::minimumFromConstraint($constraint))->toBe($expected);
})->with([
    ['>=8.4.1', '8.4.1'],
    ['^8.4', '8.4'],
    ['~8.3.0', '8.3.0'],
    ['8.2.*', '8.2'],
]);

test('a site already on the required major.minor does not need an upgrade', function () {
    $site = new Site([
        'runtime' => 'php',
        'runtime_version' => '8.4',
    ]);

    $output = 'symfony/console v8.1.0 requires php >=8.4.1 -> your php version (8.4.5) does not satisfy that requirement.';

    expect(PhpVersionUpgradePlanner::targetForSite($site, $output))->toBeNull();
});

test('a site on 8.3 is upgraded to 8.4 when composer requires 8.4.1', function () {
    $site = new Site([
        'runtime' => 'php',
        'runtime_version' => '8.3',
    ]);

    $output = 'your php version (8.3.6) does not satisfy that requirement. requires php >=8.4.1';

    expect(PhpVersionUpgradePlanner::targetForSite($site, $output))->toBe('8.4');
});

test('a repo pin of ^8.5 suggests 8.5 when the site is on 8.3', function () {
    $site = new Site([
        'runtime' => 'php',
        'runtime_version' => '8.3',
        'meta' => [
            'vm_runtime' => [
                'detected' => ['version' => '^8.5'],
            ],
        ],
    ]);

    expect(PhpVersionUpgradePlanner::targetForSite($site))->toBe('8.5');
});

test('unrelated composer output does not look like a php upgrade', function () {
    expect(PhpVersionUpgradePlanner::requiredFromOutput('Your requirements could not be resolved to an installable set of packages.'))
        ->toBeNull();
});
