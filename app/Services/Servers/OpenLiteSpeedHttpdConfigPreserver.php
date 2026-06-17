<?php

declare(strict_types=1);

namespace App\Services\Servers;

/**
 * When dply regenerates httpd_config.conf (site apply, webserver switch), merge
 * operator-tuned `module { }` blocks from the on-disk file so Modules / Cache
 * tab edits are not wiped.
 */
class OpenLiteSpeedHttpdConfigPreserver
{
    /**
     * @return array<string, string> module name => full block text
     */
    /** @return array<string, mixed> */
    public function extractModuleBlocks(string $config): array
    {
        if ($config === '') {
            return [];
        }

        $blocks = [];
        if (preg_match_all('/^[\t ]*module\s+([A-Za-z0-9_]+)\s*\{.*?^[\t ]*\}/sm', $config, $matches, PREG_SET_ORDER) !== false) {
            foreach ($matches as $match) {
                $name = (string) ($match[1] ?? '');
                if ($name === '') {
                    continue;
                }
                $blocks[$name] = rtrim((string) ($match[0] ?? ''), "\n")."\n";
            }
        }

        return $blocks;
    }

    /**
     * Replace or append module blocks from $existing into $generated.
     */
    public function merge(string $generated, string $existing): string
    {
        $existingModules = $this->extractModuleBlocks($existing);
        if ($existingModules === []) {
            return $generated;
        }

        $merged = $generated;
        foreach ($existingModules as $name => $block) {
            if ($this->containsModuleBlock($merged, $name)) {
                $merged = $this->replaceModuleBlock($merged, $name, $block);
            } else {
                $merged = rtrim($merged, "\n")."\n\n".trim($block)."\n";
            }
        }

        return $merged;
    }

    private function containsModuleBlock(string $config, string $name): bool
    {
        return preg_match('/^[\t ]*module\s+'.preg_quote($name, '/').'\s*\{/m', $config) === 1;
    }

    private function replaceModuleBlock(string $config, string $name, string $block): string
    {
        $pattern = '/^[\t ]*module\s+'.preg_quote($name, '/').'\s*\{.*?^[\t ]*\}\s*$/sm';
        $replaced = (string) preg_replace($pattern, rtrim($block, "\n"), $config, 1);

        return $replaced !== '' ? $replaced : $config;
    }
}
