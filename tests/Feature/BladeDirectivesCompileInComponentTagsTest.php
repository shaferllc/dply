<?php

declare(strict_types=1);

namespace Tests\Feature\BladeDirectivesCompileInComponentTagsTest;

use Symfony\Component\Finder\Finder;

/**
 * Blade directives do NOT compile inside an `<x-component>` opening tag.
 *
 * The component tag compiler runs first and freezes each attribute into a PHP
 * string literal, so `wire:click="pauseQueue(@js($queue))"` reaches the browser
 * as the literal text `@js($queue)`. Alpine then throws "Invalid or unexpected
 * token" and the button silently does nothing — which is how five queue
 * controls shipped dead.
 *
 * Inside a component attribute, use `{!! \Illuminate\Support\Js::from($x) !!}`:
 * echoes compile there, and it emits exactly what `@js()` would.
 */
it('leaves no uncompiled blade directive in any view', function () {
    $compiler = app('blade.compiler');
    $leaks = [];

    foreach ((new Finder)->files()->in(resource_path('views'))->name('*.blade.php') as $file) {
        $source = (string) file_get_contents($file->getRealPath());

        // Cheap pre-filter: only a view that writes the directive can leak it.
        if (! str_contains($source, '@js(')) {
            continue;
        }

        if (str_contains($compiler->compileString($source), '@js(')) {
            $leaks[] = $file->getRelativePathname();
        }
    }

    expect($leaks)->toBe([], 'Uncompiled @js() — most likely inside an <x-component> attribute: '.implode(', ', $leaks));
});
