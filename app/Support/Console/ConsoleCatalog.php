<?php

namespace App\Support\Console;

use App\Models\Server;
use App\Support\Servers\ServerInstalledServices;

/**
 * Loader for the Console help-sidebar catalog.
 *
 * Sources are PHP config files in config/console_catalog/*.php — one section
 * per file. Each file returns an array:
 *
 *   [
 *     'label' => 'PHP',
 *     'description' => '…',                  // optional
 *     'requires_any_tags' => ['php'],        // omit/empty = always shown
 *     'entries' => [ ['command' => '…', 'description' => '…'], … ],
 *   ]
 *
 * Section visibility uses the same tag mechanism as the workspace sidebar
 * (ServerInstalledServices::tagsFor) so a section appears only when the
 * relevant service was provisioned.
 *
 * Commands may contain a small set of placeholders that are substituted
 * server-specifically at load time (see self::placeholders()).
 */
class ConsoleCatalog
{
    /**
     * @return list<array{
     *     id: string,
     *     label: string,
     *     description: ?string,
     *     haystack: string,
     *     entries: list<array{command: string, description: ?string, haystack: string}>
     * }>
     */
    public static function for(Server $server): array
    {
        $tags = ServerInstalledServices::tagsFor($server);
        $placeholders = self::placeholders($server);

        $sections = [];
        foreach (self::sectionFiles() as $id => $path) {
            $raw = require $path;
            if (! is_array($raw)) {
                continue;
            }

            $required = $raw['requires_any_tags'] ?? [];
            if (is_array($required) && $required !== [] && ! self::tagsMatch($required, $tags)) {
                continue;
            }

            $entries = [];
            foreach ((array) ($raw['entries'] ?? []) as $entry) {
                if (! is_array($entry) || empty($entry['command'])) {
                    continue;
                }
                $cmd = strtr((string) $entry['command'], $placeholders);
                $desc = isset($entry['description']) ? (string) $entry['description'] : null;
                $entries[] = [
                    'command' => $cmd,
                    'description' => $desc,
                    'haystack' => strtolower($cmd.' '.($desc ?? '')),
                ];
            }

            if ($entries === []) {
                continue;
            }

            $label = (string) ($raw['label'] ?? ucfirst($id));
            $description = isset($raw['description']) ? (string) $raw['description'] : null;
            $sectionHaystack = strtolower($label.' '.($description ?? '').' '.implode(' ', array_column($entries, 'haystack')));

            $sections[] = [
                'id' => $id,
                'label' => $label,
                'description' => $description,
                'haystack' => $sectionHaystack,
                'entries' => $entries,
            ];
        }

        return $sections;
    }

    /**
     * @return array<string, string> filename-stem => absolute path
     */
    protected static function sectionFiles(): array
    {
        $dir = config_path('console_catalog');
        if (! is_dir($dir)) {
            return [];
        }

        $files = glob($dir.'/*.php') ?: [];
        sort($files);
        $out = [];
        foreach ($files as $file) {
            $out[basename($file, '.php')] = $file;
        }

        return $out;
    }

    /**
     * @param  list<string>  $required
     * @param  array<string, true>  $installed
     */
    protected static function tagsMatch(array $required, array $installed): bool
    {
        // Fail open: when the stack summary is unavailable, ServerInstalledServices
        // returns 'unknown' so we don't blank out the UI on freshly-imported boxes.
        if (array_key_exists('unknown', $installed)) {
            return true;
        }
        foreach ($required as $tag) {
            if (is_string($tag) && array_key_exists($tag, $installed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Placeholder substitutions applied to every entry's command string.
     *
     * Kept tiny on purpose — the catalog is curated content, not a templating
     * engine. Add only what's needed to keep entries authentic per-server.
     *
     * @return array<string, string>
     */
    protected static function placeholders(Server $server): array
    {
        $phpVersion = ServerInstalledServices::phpVersionFor($server);

        return [
            // php-fpm{php_version} → php-fpm8.3 ; php{php_version}-fpm → php8.3-fpm
            '{php_version}' => $phpVersion ?? '',
        ];
    }
}
