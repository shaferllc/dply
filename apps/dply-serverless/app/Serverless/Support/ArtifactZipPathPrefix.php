<?php

namespace App\Serverless\Support;

use RuntimeException;

/**
 * Narrows the allowed on-disk zip artifact directory using optional project settings.
 */
final class ArtifactZipPathPrefix
{
    /**
     * When {@see $settingsKey} is set on {@see $providerConfig} under `project.settings`, it must
     * resolve to the global prefix or a subdirectory thereof (both paths must exist).
     *
     * @param  array<string, mixed>  $providerConfig
     */
    public static function resolve(string $globalPrefix, array $providerConfig, string $settingsKey): string
    {
        $globalPrefix = rtrim($globalPrefix, DIRECTORY_SEPARATOR);
        $realGlobal = realpath($globalPrefix);
        if ($realGlobal === false) {
            throw new RuntimeException('Global zip path prefix is not resolvable on disk.');
        }

        $settings = [];
        if (isset($providerConfig['project']['settings']) && is_array($providerConfig['project']['settings'])) {
            $settings = $providerConfig['project']['settings'];
        }

        $raw = trim((string) ($settings[$settingsKey] ?? ''));
        if ($raw === '') {
            return $realGlobal;
        }

        $sub = rtrim($raw, DIRECTORY_SEPARATOR);
        $realSub = realpath($sub);
        if ($realSub === false) {
            throw new RuntimeException('Project zip path prefix is not resolvable on disk: '.$sub);
        }

        $prefixWithSep = rtrim($realGlobal, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if ($realSub !== $realGlobal && ! str_starts_with($realSub, $prefixWithSep)) {
            throw new RuntimeException(
                'Project zip path prefix must be the same as or a subdirectory of the global deploy zip path prefix.'
            );
        }

        return $realSub;
    }
}
