<?php

declare(strict_types=1);

namespace Tests\Feature\SiteMetaWritesTest;

use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('saving a stale copy writes only the meta keys it changed', function () {
    $site = Site::factory()->create(['meta' => ['caching' => ['enabled' => false], 'legacy' => true]]);

    // Loaded before another writer lands — a Livewire page left open.
    $stale = Site::query()->findOrFail($site->id);
    $site->putMeta('horizon', ['status' => 'running']);

    $meta = $stale->meta;
    $meta['caching'] = ['enabled' => true];
    unset($meta['legacy']);
    $stale->forceFill(['meta' => $meta, 'name' => 'renamed'])->save();

    expect($site->fresh()->meta)->toEqualCanonicalizing([
        'caching' => ['enabled' => true],
        'horizon' => ['status' => 'running'],
    ])
        ->and($site->fresh()->name)->toBe('renamed')
        // Observers and the actions layer still see meta as changed.
        ->and($stale->wasChanged('meta'))->toBeTrue();
});

test('a site whose meta was saved empty — [] in JSON — can still be written', function () {
    // PHP encodes an empty array as `[]`; jsonb_set on a JSON array wants
    // integer paths and threw on every save that touched such a site.
    $site = Site::factory()->create();
    Site::query()->whereKey($site->id)->toBase()->update(['meta' => '[]']);

    $fresh = Site::query()->findOrFail($site->id);
    $fresh->forceFill(['meta' => ['container' => ['source' => 'acme/api']]])->save();
    $fresh->putMeta('horizon', ['status' => 'running']);

    expect($site->fresh()->meta)->toEqualCanonicalizing([
        'container' => ['source' => 'acme/api'],
        'horizon' => ['status' => 'running'],
    ]);
});

test('a meta-only save on a NULL column still lands', function () {
    $site = Site::factory()->create();
    Site::query()->whereKey($site->id)->toBase()->update(['meta' => null]);

    $fresh = Site::query()->findOrFail($site->id);
    $fresh->forceFill(['meta' => ['queue_paused' => ['emails' => ['1' => 'cmd']]]])->save();

    expect($site->fresh()->meta)->toBe(['queue_paused' => ['emails' => ['1' => 'cmd']]]);
});
