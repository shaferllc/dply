<?php

namespace Tests\Unit\Deploy\DplyManifestKindTest;

use App\Modules\Deploy\Services\Manifest\DplyManifestException;
use App\Modules\Deploy\Services\Manifest\DplyManifestParser;

/**
 * `kind:` declares which dply product a repository wants to be, so `dply init`
 * does not have to ask. It is read at create time only — a deploy never
 * migrates an existing site from one product to another on the strength of a
 * file changing.
 */
it('parses a declared kind', function (string $kind) {
    expect((new DplyManifestParser)->parseArray(['kind' => $kind])->kind)->toBe($kind);
})->with(['vm', 'cloud', 'edge', 'serverless']);

it('normalises case and surrounding space', function () {
    expect((new DplyManifestParser)->parseArray(['kind' => '  Serverless '])->kind)->toBe('serverless');
});

it('treats a missing kind as undeclared', function () {
    expect((new DplyManifestParser)->parseArray([])->kind)->toBeNull();
    expect((new DplyManifestParser)->parseArray(['kind' => ''])->kind)->toBeNull();
    expect((new DplyManifestParser)->parseArray(['kind' => '   '])->kind)->toBeNull();
});

it('reports a misspelled kind rather than ignoring it', function () {
    // Silently dropping this would send someone to the wrong product menu with
    // no explanation of why.
    expect(fn () => (new DplyManifestParser)->parseArray(['kind' => 'lambda']))
        ->toThrow(DplyManifestException::class, 'must be one of: vm, cloud, edge, serverless');
});

it('rejects a non-string kind', function () {
    expect(fn () => (new DplyManifestParser)->parseArray(['kind' => ['serverless']]))
        ->toThrow(DplyManifestException::class, 'must be a string');
});

it('no longer warns about kind as an unknown key', function () {
    $manifest = (new DplyManifestParser)->parseArray(['kind' => 'serverless', 'runtime' => 'php']);

    expect(implode(' ', $manifest->warnings))->not->toContain('kind');
});
