<?php

declare(strict_types=1);

namespace Tests\Feature\TmpEnvMarkupNestingTest;

use App\Livewire\Sites\Settings as SitesSettings;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function makeUserSite(array $siteAttrs = []): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'ssh_private_key' => 'fake-key',
    ]);
    $site = Site::factory()->create(array_merge([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ], $siteAttrs));

    return [$user, $server, $site];
}

/**
 * Temporary scaffold: parses the rendered Environment page and fails on any
 * DOM nesting error, which tag-counting cannot catch.
 */
function nestingErrors(string $html): array
{
    $prev = libxml_use_internal_errors(true);
    libxml_clear_errors();

    $doc = new \DOMDocument;
    $doc->loadHTML('<!DOCTYPE html><html><body>'.$html.'</body></html>');

    $errors = [];
    foreach (libxml_get_errors() as $e) {
        $msg = trim($e->message);
        // Unknown/custom attributes and Blade-ish artefacts are not nesting bugs.
        if (str_contains($msg, 'Tag ') && str_contains($msg, 'invalid')) {
            continue;
        }
        if (str_contains($msg, 'error parsing attribute name')) {
            continue;
        }
        // Pre-existing codebase-wide pattern: heroicon SVGs already carry
        // aria-hidden, and call sites pass it again. Not a nesting bug.
        if (str_contains($msg, 'redefined')) {
            continue;
        }
        $errors[] = "line {$e->line}: {$msg}";
    }

    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    return $errors;
}

test('environment page markup nests correctly with variables present', function () {
    [$user, $server, $site] = makeUserSite();

    $site->forceFill([
        'env_file_content' => implode("\n", [
            '# a comment on APP_NAME',
            'APP_NAME=demo',
            'APP_KEY=',
            'APP_DEBUG=true',
            'APP_ENV=local',
            'DB_CONNECTION=pgsql',
            'DB_PASSWORD=',
            'BROADCAST_CONNECTION=reverb',
            'REVERB_APP_KEY=',
            'REVERB_APP_ID=',
            'REVERB_APP_SECRET=',
            'MAIL_HOST=smtp.example.com',
        ]),
        'env_cache_origin' => 'server',
    ])->save();

    $html = Livewire::actingAs($user)
        ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
        ->html();

    // The page must actually contain the rows/warnings we restyled.
    expect($html)->toContain('REVERB_APP_KEY');
    expect($html)->toContain('Environment variables');

    expect(nestingErrors($html))->toBe([]);
});

test('environment page markup nests correctly while editing a row', function () {
    [$user, $server, $site] = makeUserSite();

    $site->forceFill(['env_file_content' => "APP_NAME=demo\nDB_PASSWORD=x"])->save();

    $html = Livewire::actingAs($user)
        ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
        ->call('editEnvVar', 'APP_NAME')
        ->html();

    expect(nestingErrors($html))->toBe([]);
});
