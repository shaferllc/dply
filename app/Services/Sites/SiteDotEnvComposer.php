<?php

namespace App\Services\Sites;

use App\Models\Site;

class SiteDotEnvComposer
{
    public function compose(Site $site): string
    {
        $site->loadMissing(['environmentVariables', 'workspace.variables']);
        $envName = $site->deployment_environment ?: 'production';

        $map = $this->parseDotenv((string) ($site->env_file_content ?? ''));

        if ($site->workspace) {
            foreach ($site->workspace->variables as $row) {
                $map[$row->env_key] = (string) ($row->env_value ?? '');
            }
        }

        foreach ($site->environmentVariables as $row) {
            if ($row->environment === $envName) {
                $map[$row->env_key] = (string) $row->env_value;
            }
        }

        ksort($map);

        $lines = [];
        foreach ($map as $key => $value) {
            $lines[] = $key.'='.$this->escapeValue($value);
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<string, string>
     */
    protected function parseDotenv(string $raw): array
    {
        $map = [];
        foreach (preg_split("/\r\n|\n|\r/", $raw) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (! str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v);
            if ($k !== '') {
                $map[$k] = $this->unquoteValue($v);
            }
        }

        return $map;
    }

    protected function unquoteValue(string $v): string
    {
        if (strlen($v) >= 2 && (($v[0] === '"' && str_ends_with($v, '"')) || ($v[0] === "'" && str_ends_with($v, "'")))) {
            return stripcslashes(substr($v, 1, -1));
        }

        return $v;
    }

    protected function escapeValue(string $value): string
    {
        if ($value === '') {
            return '""';
        }
        if (preg_match('/[\s"#$\\\\]/', $value)) {
            return '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value).'"';
        }

        return $value;
    }
}
