<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Cli\CliCatalogParityTest;

use App\Support\Cli\DplyCliCommandCatalog;

/**
 * The catalog is hand-maintained PHP; the commands are JavaScript. Nothing
 * connected the two, so both drifts happened: the catalog advertised
 * `dply server run --command` shapes for a CLI it could not see, and
 * `dply site artisan` shipped with no catalog row at all.
 *
 * packages/dply-cli/commands.json is the contract. The CLI's own test asserts
 * that file matches its live dispatch table; this one asserts the catalog only
 * advertises what the file lists. Neither side can drift alone.
 */
function manifest(): array
{
    $path = base_path('packages/dply-cli/commands.json');

    expect(file_exists($path))->toBeTrue('packages/dply-cli/commands.json is missing — run `npm run manifest` in packages/dply-cli');

    /** @var array{commands: list<array{id: string, aliases: list<string>, subcommands?: list<string>}>, shortcuts: list<string>} $decoded */
    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    return $decoded;
}

/** @return array<string, list<string>> canonical id => subcommands */
function commandIndex(): array
{
    $manifest = manifest();
    $index = [];

    // Multi-token shortcuts (`dply servers` => `server list`) are valid input,
    // so a row may name one; they take no subcommand of their own.
    foreach ($manifest['shortcuts'] ?? [] as $shortcut) {
        $index[$shortcut] = [];
    }

    foreach ($manifest['commands'] as $command) {
        foreach ([$command['id'], ...($command['aliases'] ?? [])] as $name) {
            $index[$name] = $command['subcommands'] ?? [];
        }
    }

    return $index;
}

/**
 * `dply site artisan --site <site> -- migrate` => ['site', 'artisan'].
 * Flags, placeholders and everything after `--` are not command names.
 *
 * @return array{0: string, 1: ?string}
 */
function commandWords(string $example): array
{
    $words = [];
    foreach (preg_split('/\s+/', trim($example)) ?: [] as $word) {
        if ($word === '--') {
            break;
        }
        if ($word === 'dply' || str_starts_with($word, '-') || str_starts_with($word, '<') || str_starts_with($word, '"')) {
            continue;
        }
        $words[] = $word;
        if (count($words) === 2) {
            break;
        }
    }

    return [$words[0] ?? '', $words[1] ?? null];
}

it('advertises only commands the CLI actually ships', function () {
    $index = commandIndex();
    $unknown = [];

    foreach (DplyCliCommandCatalog::entries() as $row) {
        $command = (string) ($row['command'] ?? '');
        if ($command === '') {
            continue;
        }

        [$top] = commandWords($command);
        if ($top !== '' && ! array_key_exists($top, $index)) {
            $unknown[] = ($row['id'] ?? '?').': '.$command;
        }
    }

    expect($unknown)->toBe([], "catalog rows naming a command the CLI does not dispatch:\n".implode("\n", $unknown));
});

it('advertises only subcommands the owning command accepts', function () {
    $index = commandIndex();
    $unknown = [];

    foreach (DplyCliCommandCatalog::entries() as $row) {
        $command = (string) ($row['command'] ?? '');
        [$top, $sub] = commandWords($command);

        if ($sub === null || ! array_key_exists($top, $index) || $index[$top] === []) {
            continue;
        }

        if (! in_array($sub, $index[$top], true)) {
            $unknown[] = ($row['id'] ?? '?').': '.$command;
        }
    }

    expect($unknown)->toBe([], "catalog rows naming an unknown subcommand:\n".implode("\n", $unknown));
});

it('gives every shipped site and server subcommand a catalog row', function () {
    $advertised = [];
    foreach (DplyCliCommandCatalog::entries() as $row) {
        [$top, $sub] = commandWords((string) ($row['command'] ?? ''));
        if ($sub !== null) {
            $advertised[] = "{$top} {$sub}";
        }
    }

    $index = commandIndex();
    // Help and list aliases are navigation, not capabilities worth a row.
    $exempt = ['help', 'ls', 'deploys', 'list'];
    $missing = [];

    foreach (['site', 'server'] as $group) {
        foreach ($index[$group] ?? [] as $sub) {
            if (in_array($sub, $exempt, true)) {
                continue;
            }
            if (! in_array("{$group} {$sub}", $advertised, true)) {
                $missing[] = "{$group} {$sub}";
            }
        }
    }

    expect($missing)->toBe([], "shipped but not advertised in the catalog:\n".implode("\n", $missing));
});
