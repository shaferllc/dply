<?php

declare(strict_types=1);

use App\Models\SiteBinding;
use App\Support\Sites\SiteBindingCatalog;
use Illuminate\Support\Collection;

/*
| SiteBindingCatalog is the single source of truth for the site Resources hub:
| which binding types exist, how they group, and which runtimes each applies
| to. The hub renders straight off grouped(), so the shape assertions here are
| what keep the view from silently losing a card.
|
| No database — grouped() only reads `type` off the bindings it is handed, so
| unsaved SiteBinding instances are enough.
*/

/** @param list<string> $types */
function bindingsOfType(array $types): Collection
{
    return collect($types)->map(fn (string $type) => new SiteBinding(['type' => $type]));
}

test('groups are ordered data, delivery, integrations, runtime', function () {
    expect(array_keys(SiteBindingCatalog::GROUPS))
        ->toBe(['data', 'delivery', 'integrations', 'runtime']);
});

test('every type declares the metadata the hub renders and points at a real group', function () {
    $groups = array_keys(SiteBindingCatalog::GROUPS);

    foreach (SiteBindingCatalog::types() as $type => $meta) {
        expect($meta)->toHaveKeys(['group', 'label', 'icon', 'purpose', 'env', 'runtimes'])
            ->and($meta['group'])->toBeIn($groups)
            ->and($meta['label'])->not->toBe('')
            ->and($meta['icon'])->toStartWith('heroicon-')
            ->and($meta['purpose'])->not->toBe('')
            ->and($meta['env'])->toBeArray()
            ->and($meta['runtimes'])->toBeArray()->not->toBeEmpty();

        // `needs` is optional, but when present must reference declared types.
        foreach ($meta['needs'] ?? [] as $need) {
            expect($need)->toBeIn(array_keys(SiteBindingCatalog::types()));
        }

        expect($type)->toBe(strtolower($type));
    }
});

test('grouped filters types down to the requested runtime', function () {
    $grouped = SiteBindingCatalog::grouped('vm', collect());

    expect($grouped)->not->toBeEmpty();

    foreach ($grouped as $group) {
        foreach ($group['types'] as $entry) {
            $meta = SiteBindingCatalog::types()[$entry['type']];
            expect($meta['runtimes'])->toContain('vm');
        }
    }

    // No type declares this runtime yet, so every group empties out and is
    // dropped rather than rendering as a bare heading.
    expect(SiteBindingCatalog::grouped('nonexistent-runtime', collect()))->toBe([]);
});

test('grouped drops groups that end up empty for the runtime', function () {
    $grouped = SiteBindingCatalog::grouped('vm', collect());

    foreach ($grouped as $key => $group) {
        expect($group['types'])->not->toBeEmpty()
            ->and($group['label'])->toBe(SiteBindingCatalog::GROUPS[$key]);
    }
});

test('an attached binding is resolved onto its type and marks it attached', function () {
    $grouped = SiteBindingCatalog::grouped('vm', bindingsOfType(['redis']));

    $entries = collect($grouped)->flatMap(fn ($g) => $g['types'])->keyBy('type');

    expect($entries['redis']['attached'])->toBeTrue()
        ->and($entries['redis']['binding'])->toBeInstanceOf(SiteBinding::class)
        ->and($entries['mail']['attached'])->toBeFalse()
        ->and($entries['mail']['binding'])->toBeNull();
});

test('multi-instance types collect every matching row, single types stay null', function () {
    $grouped = SiteBindingCatalog::grouped('vm', bindingsOfType(['redis', 'redis', 'queue']));

    $entries = collect($grouped)->flatMap(fn ($g) => $g['types'])->keyBy('type');

    // redis is multi-instance: the hub renders the full list.
    expect(SiteBinding::isMultiInstance('redis'))->toBeTrue()
        ->and($entries['redis']['bindings'])->toHaveCount(2);

    // queue shares a single selector key (QUEUE_CONNECTION), so it stays
    // single-instance: `bindings` is null and only `binding` is used.
    expect(SiteBinding::isMultiInstance('queue'))->toBeFalse()
        ->and($entries['queue']['bindings'])->toBeNull()
        ->and($entries['queue']['attached'])->toBeTrue();
});

test('every multi-instance type is present in the catalog', function () {
    // The two lists are maintained separately — a type listed as multi-instance
    // but absent from the catalog would never render its extra rows.
    foreach (SiteBinding::MULTI_INSTANCE_TYPES as $type) {
        expect(SiteBindingCatalog::types())->toHaveKey($type);
    }
});

test('grouped exposes needs so the hub can gate dependent types', function () {
    $entries = collect(SiteBindingCatalog::grouped('vm', collect()))
        ->flatMap(fn ($g) => $g['types'])
        ->keyBy('type');

    // `cache` is declared as needing `redis`; every entry carries a list.
    expect($entries['cache']['needs'])->toContain('redis');

    foreach ($entries as $entry) {
        expect($entry['needs'])->toBeArray();
    }
});

test('every catalog type is exposed by grouped for the vm runtime', function () {
    $vmTypes = collect(SiteBindingCatalog::types())
        ->filter(fn (array $meta) => in_array('vm', $meta['runtimes'], true))
        ->keys()
        ->sort()
        ->values();

    $grouped = collect(SiteBindingCatalog::grouped('vm', collect()))
        ->flatMap(fn ($g) => $g['types'])
        ->pluck('type')
        ->sort()
        ->values();

    expect($grouped)->toEqual($vmTypes);
});
