<?php

declare(strict_types=1);

/**
 * Revert invalid native PHP generics introduced by an over-aggressive docblock pass.
 * PHP does not support array<string, mixed> in native signatures — only in PHPDoc.
 */
$root = dirname(__DIR__);
$dirs = [$root.'/app/Services', $root.'/app/Support', $root.'/app/TaskRunner'];
$changed = 0;

foreach ($dirs as $dir) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $fileInfo) {
        if ($fileInfo->getExtension() !== 'php') {
            continue;
        }

        $path = $fileInfo->getPathname();
        $original = file_get_contents($path);
        if ($original === false) {
            continue;
        }

        $content = $original;

        // Any generic array/list in native parameter types (incl. by-ref) → plain array
        $content = preg_replace(
            '/(?<![@\/\*])\b(?:array|list)<[^>]+>\s+(&?\$\w+)/',
            'array $1',
            $content
        ) ?? $content;

        // Any generic array/list in native return types → plain array
        $content = preg_replace(
            '/\):\s*\?(?:array|list)<[^>]+>/',
            '): ?array',
            $content
        ) ?? $content;

        $content = preg_replace(
            '/\):\s*(?:array|list)<[^>]+>/',
            '): array',
            $content
        ) ?? $content;

        // Legacy explicit string,mixed forms (kept for idempotency)
        $content = preg_replace(
            '/(?<![@\w])array<string,\s*mixed>\s+(\$\w+)/',
            'array $1',
            $content
        ) ?? $content;

        // Native return types: ): ?array<string, mixed> → ): ?array
        $content = preg_replace(
            '/\):\s*\?array<string,\s*mixed>/',
            '): ?array',
            $content
        ) ?? $content;

        // Native return types: ): array<string, mixed> → ): array
        $content = preg_replace(
            '/\):\s*array<string,\s*mixed>/',
            '): array',
            $content
        ) ?? $content;

        // Native property types (promoted or typed properties)
        $content = preg_replace(
            '/(?<![@\w])array<string,\s*mixed>\s+(\$\w+\s*[;,=])/',
            'array $1',
            $content
        ) ?? $content;

        // list<string> in native signatures (also invalid)
        $content = preg_replace(
            '/(?<![@\w])list<[^>]+>\s+(\$\w+)/',
            'array $1',
            $content
        ) ?? $content;

        $content = preg_replace(
            '/\):\s*list<[^>]+>/',
            '): array',
            $content
        ) ?? $content;

        if ($content !== $original) {
            $changed++;
            file_put_contents($path, $content);
            echo "Fixed: {$path}\n";
        }
    }
}

echo "Done. {$changed} file(s) fixed.\n";
