<?php

declare(strict_types=1);

namespace App\Services\Servers\Concerns;

use App\Models\Server;

/**
 * Catalog lookup plus the safety rules for per-extension actions, mirroring
 * {@see GuardsPhpPackageActions} for per-version ones.
 *
 * Every rule here has to hold for the panel's free-text field as well as for
 * catalog rows, so nothing trusts the catalog alone — the slug pattern and the
 * protected list are checked on the raw input first.
 */
trait GuardsPhpExtensionActions
{
    /**
     * apt suffixes that must never be purged: removing any of them takes the
     * PHP version itself apart, which is what the version row's Uninstall is
     * for (it migrates sites and reassigns defaults first).
     */
    protected const PROTECTED_SUFFIXES = ['common', 'cli', 'fpm', 'phpdbg', 'cgi'];

    /** Debian package suffixes are lowercase alnum with dashes/underscores. */
    protected const EXTENSION_PATTERN = '/^[a-z][a-z0-9_-]{0,39}$/';

    /**
     * The catalog, filtered to entries valid for this PHP version.
     *
     * @return list<array<string, mixed>>
     */
    public function extensionCatalog(string $version): array
    {
        $rows = [];

        foreach ((array) config('server_php_extensions.extensions', []) as $row) {
            if (! is_array($row) || ! is_string($row['id'] ?? null)) {
                continue;
            }

            $min = is_string($row['min_php'] ?? null) ? $row['min_php'] : null;
            $max = is_string($row['max_php'] ?? null) ? $row['max_php'] : null;

            if ($min !== null && version_compare($version, $min, '<')) {
                continue;
            }

            if ($max !== null && version_compare($version, $max, '>')) {
                continue;
            }

            $rows[] = [
                'id' => $row['id'],
                'label' => is_string($row['label'] ?? null) ? $row['label'] : $row['id'],
                'description' => is_string($row['description'] ?? null) ? $row['description'] : '',
                'category' => is_string($row['category'] ?? null) ? $row['category'] : 'other',
                'modules' => $this->extensionModules($row),
                'pecl' => (bool) ($row['pecl'] ?? false),
                'bundled' => (bool) ($row['bundled'] ?? false),
                'note' => is_string($row['note'] ?? null) ? $row['note'] : null,
            ];
        }

        return $rows;
    }

    /**
     * Single catalog entry, or null when the id is not curated (free-text).
     *
     * @return array<string, mixed>|null
     */
    public function extensionCatalogEntry(string $version, string $extension): ?array
    {
        foreach ($this->extensionCatalog($version) as $row) {
            if ($row['id'] === $extension) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Module names a package provides. Falls back to the package suffix itself,
     * which is right for the long tail of single-module PECL extensions.
     *
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    protected function extensionModules(array $row): array
    {
        $modules = $row['modules'] ?? null;

        if (! is_array($modules) || $modules === []) {
            return is_string($row['id'] ?? null) ? [$row['id']] : [];
        }

        return array_values(array_filter(
            array_map(static fn ($m): string => is_string($m) ? trim($m) : '', $modules),
            static fn (string $m): bool => $m !== '',
        ));
    }

    public function normalizeExtensionId(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        // Operators reach for the full package name out of habit; accept
        // "php8.3-imagick" and "php-imagick" as the bare suffix.
        $value = strtolower(trim($value));
        $value = preg_replace('/^php[0-9.]*-/', '', $value) ?? $value;

        if ($value === '' || preg_match(self::EXTENSION_PATTERN, $value) !== 1) {
            return null;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>|null  $entry
     */
    protected function guardExtensionAction(
        Server $server,
        string $action,
        string $version,
        string $extension,
        array $installedVersionIds,
        ?array $entry,
    ): void {
        if (! in_array($action, ['install', 'uninstall', 'enable', 'disable'], true)) {
            throw new \RuntimeException('Unknown PHP extension action.');
        }

        if (! in_array($version, $installedVersionIds, true)) {
            throw new \RuntimeException('Install PHP '.$version.' before managing its extensions.');
        }

        if (in_array($extension, self::PROTECTED_SUFFIXES, true)) {
            throw new \RuntimeException(
                'php'.$version.'-'.$extension.' is part of the PHP runtime itself. '
                .'Uninstall the whole version from its row instead.',
            );
        }

        if ($action === 'uninstall' && ($entry['bundled'] ?? false)) {
            throw new \RuntimeException(
                ($entry['label'] ?? $extension).' ships inside php'.$version.'-common and cannot be removed on its own. '
                .'Disable it instead.',
            );
        }

        if ($action === 'install' && ($entry['bundled'] ?? false)) {
            throw new \RuntimeException(
                ($entry['label'] ?? $extension).' ships inside php'.$version.'-common and is already present. '
                .'Enable it instead.',
            );
        }
    }
}
