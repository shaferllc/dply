<?php

declare(strict_types=1);

namespace Tests\Unit\ConfirmModalRenderedTest;

/**
 * A destructive action with no confirmation.
 *
 * `<x-slot name="modals">` only binds to an enclosing Blade component. On a page
 * whose root is a plain <div> the slot is dropped, so the confirm dialog never
 * renders — promptDestroyCredential() set the modal state and nothing appeared,
 * making Remove look broken.
 *
 * This had already been fixed twice (organizations/api-tokens, then
 * organizations/secrets), each time with a comment explaining why. The pattern
 * kept coming back, so assert it instead of re-discovering it.
 */
test('no page puts the confirm modal in a slot its root cannot bind', function () {
    $views = rtrim((string) resource_path('views/livewire'), '/');
    $offenders = [];

    $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($views));

    foreach ($files as $file) {
        if (! $file->isFile() || ! str_ends_with((string) $file, '.blade.php')) {
            continue;
        }

        $source = (string) file_get_contents((string) $file);

        if (! str_contains($source, 'confirm-action-modal')) {
            continue;
        }

        // Only a component root can carry a slot. A plain-div root cannot.
        $rootIsComponent = (bool) preg_match('/^\s*(?:\{\{--.*?--\}\}\s*|@php.*?@endphp\s*)*<x-/s', $source);

        $inSlotOnly = str_contains($source, '<x-slot name="modals">')
            && ! preg_match("/@include\('livewire\.partials\.confirm-action-modal'\)(?![^<]*<\/x-slot>)/", $source);

        if ($inSlotOnly && ! $rootIsComponent) {
            $offenders[] = str_replace($views.'/', '', (string) $file);
        }
    }

    expect($offenders)->toBe([]);
});
