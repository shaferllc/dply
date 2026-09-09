<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Sites\WorkspaceSectionRegistrationTest;

/**
 * Settings::mount() aborts 404 on any section missing from
 * config('site_settings.workspace_tabs'). A sidebar item without a matching
 * entry is a nav link straight to a 404 — which is exactly what shipping the
 * Queue section without registering it produced.
 */
test('every routeless sidebar section is a registered workspace tab', function () {
    $allowed = array_keys(config('site_settings.workspace_tabs', []));

    expect($allowed)->toContain('queue')
        ->and($allowed)->toContain('worker-fleet');
});
