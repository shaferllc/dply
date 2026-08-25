<?php

declare(strict_types=1);

namespace Tests\Unit\Services\PipelineAnchorRemoteMatchTest;

use App\Services\Sites\PipelineAnchorScriptRunner;

/**
 * Changing a site's repository URL left the old clone in place: fetch populated
 * FETCH_HEAD, checkout landed on the OLD repo's branch, and the pull died on
 * unrelated histories — so the deploy kept building the previous repository.
 */
function normalize(string $url): string
{
    $m = new \ReflectionMethod(PipelineAnchorScriptRunner::class, 'normalizeRemote');
    $m->setAccessible(true);

    return $m->invoke(null, $url);
}

test('the same repo matches across url spellings', function () {
    $canonical = normalize('https://github.com/tshafer/divineiv2.git');

    // Trailing .git, trailing slash, and case must not read as a different repo.
    expect(normalize('https://github.com/tshafer/divineiv2'))->toBe($canonical)
        ->and(normalize('https://github.com/tshafer/divineiv2.git/'))->toBe($canonical)
        ->and(normalize('https://GitHub.com/tshafer/DivineIV2.git'))->toBe($canonical);
});

test('an injected token still matches the clean stored remote', function () {
    // Private HTTPS repos are fetched with a token spliced into the URL, while
    // origin stores the credential-stripped form. These are the same repo.
    expect(normalize('https://x-access-token:ghs_secret@github.com/tshafer/divineiv2.git'))
        ->toBe(normalize('https://github.com/tshafer/divineiv2.git'));
});

test('a different repo does not match', function () {
    // The exact bug: divineiv vs divineiv2.
    expect(normalize('https://github.com/tshafer/divineiv.git'))
        ->not->toBe(normalize('https://github.com/tshafer/divineiv2.git'));
});
