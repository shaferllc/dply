<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Servers\ServerFileBrowserNumericNamesTest;

use App\Services\Servers\ServerFileBrowserRemoteReader;

/**
 * parseLsLines() keys its arrays by filename, and PHP coerces a numeric-string
 * array key to int. Atomic release directories are timestamps
 * (`20260803143955`), so listing `releases/` handed an int to
 * FileBrowserEntry's string $name and threw a TypeError — the whole directory
 * failed to list.
 */
function mergeVia(array $resolved, array $linkInfo): array
{
    $reader = new ServerFileBrowserRemoteReader;
    $merge = (new \ReflectionClass($reader))->getMethod('mergeListings');
    $merge->setAccessible(true);

    return $merge->invoke($reader, $resolved, $linkInfo);
}

function lsRow(string $type = 'dir'): array
{
    return [
        'type' => $type,
        'size' => 4096,
        'mtime' => 1_767_000_000,
        'mode' => 'drwxr-xr-x',
        'owner' => 'dply',
        'group' => 'dply',
        'link_target' => null,
    ];
}

test('a numeric release directory name survives as a string', function (): void {
    // Written with a numeric key exactly as PHP stores it after $out[$name].
    $entries = mergeVia(['20260803143955' => lsRow()], ['20260803143955' => lsRow()]);

    expect($entries)->toHaveCount(1);
    expect($entries[0]->name)->toBe('20260803143955');
    expect($entries[0]->name)->toBeString();
    expect($entries[0]->isDir())->toBeTrue();
});

test('a directory of numeric release names all list', function (): void {
    $rows = [];
    foreach (['20260803143955', '20260725123515', '20260617170002'] as $folder) {
        $rows[$folder] = lsRow();
    }

    $entries = mergeVia($rows, $rows);

    expect($entries)->toHaveCount(3);
    foreach ($entries as $entry) {
        expect($entry->name)->toBeString();
    }
});

test('non-numeric names are unaffected', function (): void {
    $entries = mergeVia(['shared' => lsRow(), 'repo' => lsRow()], ['shared' => lsRow(), 'repo' => lsRow()]);

    expect(array_map(fn ($e) => $e->name, $entries))->toEqualCanonicalizing(['shared', 'repo']);
});

test('a numeric symlink still resolves its target type', function (): void {
    $link = lsRow('link');
    $link['link_target'] = '../releases/20260803143955';

    $entries = mergeVia(['12345' => lsRow('dir')], ['12345' => $link]);

    expect($entries[0]->name)->toBe('12345');
    expect($entries[0]->isLink())->toBeTrue();
    expect($entries[0]->linkTargetIsDir)->toBeTrue();
});
