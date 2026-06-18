<?php

declare(strict_types=1);

namespace Tests\Feature\LivewireAliasGuardTest;

use Livewire\Livewire;
use PHPUnit\Framework\Assert;
use Symfony\Component\Finder\Finder;

/**
 * Guard for the modular-monolith migration (docs/adr/modular-monolith-structure.md).
 *
 * Moving a Livewire component class into app/Modules/<X>/Livewire stops Livewire's
 * auto-discovery, so its kebab alias must be re-registered by the module's
 * ServiceProvider. This test asserts that every alias referenced from Blade still
 * resolves to a registered component — turning a broken `<livewire:...>` reference
 * from a production 404 into a red bar. It must be green on the current layout and
 * stay green through every module move.
 *
 * It discovers aliases dynamically from the view tree, so new components are covered
 * automatically and no hand-maintained list can drift.
 */

/**
 * JS/Alpine events that share the `livewire:` prefix but are NOT components.
 * Defensive — these appear as `livewire:init` (no `<`), not as Blade tags, but we
 * exclude them so a stray match can never produce a false failure.
 */
const NON_COMPONENT_TOKENS = [
    'init', 'navigate', 'navigated', 'navigating', 'offline', 'online', 'load',
    'dynamic-component', // resolved at runtime via :is, not a static alias
];

/** @return array<int, array{alias: string, file: string}> */
function livewireAliasReferences(): array
{
    $refs = [];

    $finder = (new Finder)
        ->files()
        ->in(resource_path('views'))
        ->name('*.blade.php');

    foreach ($finder as $file) {
        $contents = $file->getContents();
        $relative = $file->getRelativePathname();

        // <livewire:alias ...>  and  <livewire:alias />
        preg_match_all('/<livewire:([a-z0-9][a-z0-9._:-]*)/i', $contents, $tagMatches);
        // @livewire('alias')  and  @livewire("alias")  — literal string only
        preg_match_all('/@livewire\(\s*[\'"]([a-z0-9][a-z0-9._:-]*)[\'"]/i', $contents, $directiveMatches);

        foreach ([...$tagMatches[1], ...$directiveMatches[1]] as $alias) {
            if (in_array($alias, NON_COMPONENT_TOKENS, true)) {
                continue;
            }

            $refs[] = ['alias' => $alias, 'file' => $relative];
        }
    }

    return $refs;
}

test('every Livewire alias referenced in Blade resolves to a registered component', function () {
    $references = livewireAliasReferences();

    // Sanity: the view tree should contain plenty of references. If this drops to
    // zero the grep broke (e.g. Blade syntax changed), which would silently void
    // the guard — fail loudly instead.
    Assert::assertNotEmpty(
        $references,
        'No <livewire:...> / @livewire(...) references found — the alias guard regex likely needs updating.',
    );

    $broken = [];

    foreach ($references as $ref) {
        if (! Livewire::exists($ref['alias'])) {
            $broken[$ref['alias']][] = $ref['file'];
        }
    }

    $report = collect($broken)
        ->map(fn (array $files, string $alias): string => sprintf(
            '  - %s  (referenced in: %s)',
            $alias,
            implode(', ', array_unique($files)),
        ))
        ->implode("\n");

    Assert::assertSame(
        [],
        $broken,
        "These Livewire aliases are referenced in Blade but do not resolve to a registered component.\n"
            ."If you just moved a component into a module, register its alias in that module's ServiceProvider:\n\n"
            .$report,
    );
});
