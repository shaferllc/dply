<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Models\Site;

/**
 * Prepares the `.env` a serverless function deploys with.
 *
 * dply manages a function's environment in `Site.env_file_content` — the
 * same encrypted column the Environment settings panel edits. A DigitalOcean
 * Functions action has no other channel for configuration, so this writes
 * that managed env into the build artifact at deploy time.
 *
 * Two guarantees make it Vapor-like:
 *  - First deploy seeds the managed env from whatever `.env` the repo ships,
 *    so the Environment panel starts populated from the app's own config.
 *  - A Laravel function always gets a stable `APP_KEY`: generated once,
 *    persisted, and reused on every later deploy and across cold instances.
 *    Without it the encrypter throws and Laravel cannot boot.
 */
class ServerlessEnvironmentPreparer
{
    /**
     * @param  bool  $isLaravel  whether the artifact builder detected Laravel
     * @return string  a short log line describing what happened
     */
    public function prepare(Site $site, string $workingDirectory, bool $isLaravel): string
    {
        $repoEnvPath = rtrim($workingDirectory, '/').'/.env';
        $original = (string) $site->env_file_content;
        $managed = $original;
        $notes = [];

        // First deploy — seed dply's managed env from the repo's committed
        // .env so the Environment panel reflects the app's own defaults.
        if (trim($managed) === '' && is_file($repoEnvPath)) {
            $managed = (string) file_get_contents($repoEnvPath);
            if (trim($managed) !== '') {
                $notes[] = 'seeded environment from the repository .env';
            }
        }

        // Laravel needs a stable APP_KEY — generate once, persist, reuse.
        if ($isLaravel && ! $this->envHasKey($managed, 'APP_KEY')) {
            $managed = ltrim(rtrim($managed, "\r\n")."\nAPP_KEY=base64:".base64_encode(random_bytes(32))."\n", "\n");
            $notes[] = 'generated a managed APP_KEY';
        }

        // The command secret authenticates dply's background ticks
        // (scheduler / queue worker) against the deployed function.
        if ($isLaravel && ! $this->envHasKey($managed, 'DPLY_COMMAND_SECRET')
            && trim((string) $site->webhook_secret) !== '') {
            $managed = ltrim(rtrim($managed, "\r\n")."\nDPLY_COMMAND_SECRET=".trim((string) $site->webhook_secret)."\n", "\n");
        }

        // Persist so the value is stable and editable in the Environment panel.
        if ($managed !== $original) {
            $site->forceFill(['env_file_content' => $managed])->save();
        }

        // Bundle the managed env into the artifact — Laravel's Dotenv reads it.
        if (trim($managed) !== '') {
            file_put_contents($repoEnvPath, $managed);
        }

        return $notes === []
            ? 'Using the managed environment.'
            : 'Environment: '.implode(', ', $notes).'.';
    }

    /**
     * Upsert keys into a function's managed environment (`env_file_content`)
     * — existing keys are replaced in place, new ones appended. Used to wire
     * a provisioned database's connection into the function.
     *
     * @param  array<string, string>  $values
     */
    public function mergeKeys(Site $site, array $values): void
    {
        $content = (string) $site->env_file_content;
        $lines = $content === '' ? [] : (preg_split('/\r\n|\r|\n/', $content) ?: []);

        foreach ($values as $key => $value) {
            $entry = $key.'='.$this->envValue((string) $value);
            $replaced = false;
            foreach ($lines as $index => $existing) {
                if (preg_match('/^\s*'.preg_quote($key, '/').'\s*=/', (string) $existing) === 1) {
                    $lines[$index] = $entry;
                    $replaced = true;
                    break;
                }
            }
            if (! $replaced) {
                $lines[] = $entry;
            }
        }

        $site->forceFill(['env_file_content' => implode("\n", $lines)])->save();
    }

    /**
     * Quote a .env value when it contains characters Dotenv would mishandle.
     */
    private function envValue(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_.:\/+=-]+$/', $value) === 1) {
            return $value;
        }

        return '"'.str_replace('"', '\"', $value).'"';
    }

    private function envHasKey(string $env, string $key): bool
    {
        foreach (preg_split('/\r\n|\r|\n/', $env) ?: [] as $line) {
            if (preg_match('/^\s*'.preg_quote($key, '/').'\s*=\s*(.+)$/', $line, $matches) === 1
                && trim($matches[1], "\"' ") !== '') {
                return true;
            }
        }

        return false;
    }
}
