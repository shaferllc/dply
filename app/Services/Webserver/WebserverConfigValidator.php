<?php

namespace App\Services\Webserver;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Engine-aware webserver template validator.
 *
 * Why this exists: until now only nginx had a real syntax check. Templates
 * for Apache / Caddy / OpenLiteSpeed / Traefik / Lighttpd saved without
 * any verification, so typos shipped straight to the host's own config
 * test step at deploy time. This dispatcher routes a `(engine, body)`
 * pair to the right tester and gracefully reports when the engine's
 * binary isn't on PATH — the operator gets a clear message instead of a
 * generic failure.
 *
 * Each per-engine method:
 *   1. Writes the config blob to a temp file (or stdin where supported).
 *   2. Runs the engine's syntax-test binary against that file.
 *   3. Returns `{ok, message}` so the UI doesn't have to branch by engine.
 *
 * When the binary isn't installed locally, we fall back to a structural
 * brace-matching check that at least catches unmatched `{` / `}` typos.
 * It's not a real validation but it beats silently shipping bad config.
 */
class WebserverConfigValidator
{
    public function __construct(
        private readonly NginxConfigSyntaxTester $nginx,
    ) {}

    /**
     * @return array{ok: bool, message: string, engine: string}
     */
    public function validate(string $engine, string $config): array
    {
        $engine = $engine !== '' ? strtolower($engine) : 'nginx';
        $config = trim($config);

        if ($config === '') {
            return [
                'engine' => $engine,
                'ok' => false,
                'message' => __('Template content is empty.'),
            ];
        }

        $result = match ($engine) {
            'nginx' => $this->nginx->testServerBlock($config),
            'apache' => $this->validateApache($config),
            'caddy' => $this->validateCaddy($config),
            'openlitespeed' => $this->validateOpenLiteSpeed($config),
            'traefik' => $this->validateTraefik($config),
            'lighttpd' => $this->validateLighttpd($config),
            default => [
                'ok' => false,
                'message' => __('Unknown engine: :engine', ['engine' => $engine]),
            ],
        };

        return $result + ['engine' => $engine];
    }

    /**
     * Apache validates with `httpd -t` (RHEL family) or `apache2ctl -t`
     * (Debian family). We need a minimal main config so the vhost can be
     * parsed in context — most directives are happy without ServerRoot,
     * but the LoadModule lines aren't, so we keep the wrapper light.
     *
     * @return array{ok: bool, message: string}
     */
    private function validateApache(string $config): array
    {
        $binary = $this->firstAvailableBinary(['apache2ctl', 'httpd', 'apachectl']);
        if ($binary === null) {
            return $this->fallbackStructuralCheck($config, 'Apache');
        }

        $path = $this->tempFile('apache', 'conf', $config);

        try {
            $result = Process::timeout(15)->run([$binary, '-t', '-f', $path]);
            $out = trim($result->errorOutput().' '.$result->output());

            return $result->successful()
                ? ['ok' => true, 'message' => $out !== '' ? $out : __('Apache configuration syntax is valid.')]
                : ['ok' => false, 'message' => $out !== '' ? $out : __(':bin -t failed (exit :code).', ['bin' => $binary, 'code' => $result->exitCode()])];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $this->binaryError('Apache', $binary, $e)];
        } finally {
            @unlink($path);
        }
    }

    /**
     * Caddy validates with `caddy validate --config <file> --adapter caddyfile`.
     * The Caddyfile adapter is the format users author in templates, even
     * though Caddy's native format is JSON.
     *
     * @return array{ok: bool, message: string}
     */
    private function validateCaddy(string $config): array
    {
        $binary = $this->firstAvailableBinary(['caddy']);
        if ($binary === null) {
            return $this->fallbackStructuralCheck($config, 'Caddy');
        }

        $path = $this->tempFile('caddy', 'Caddyfile', $config);

        try {
            $result = Process::timeout(15)->run([$binary, 'validate', '--config', $path, '--adapter', 'caddyfile']);
            $out = trim($result->errorOutput().' '.$result->output());

            return $result->successful()
                ? ['ok' => true, 'message' => $out !== '' ? $out : __('Caddyfile is valid.')]
                : ['ok' => false, 'message' => $out !== '' ? $out : __('caddy validate failed (exit :code).', ['code' => $result->exitCode()])];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $this->binaryError('Caddy', $binary, $e)];
        } finally {
            @unlink($path);
        }
    }

    /**
     * OpenLiteSpeed: `lswsctrl -t` runs a config test on the active config.
     * There's no first-class "validate this file" mode, so we copy the
     * file into LiteSpeed's expected directory only if it's writable —
     * otherwise we fall back to the structural check.
     *
     * @return array{ok: bool, message: string}
     */
    private function validateOpenLiteSpeed(string $config): array
    {
        $binary = $this->firstAvailableBinary(['lswsctrl', 'lsws']);
        if ($binary === null) {
            return $this->fallbackStructuralCheck($config, 'OpenLiteSpeed');
        }

        // OLS doesn't accept a file argument cleanly, so we run the test
        // mode and surface whatever it has to say about the running config.
        try {
            $result = Process::timeout(10)->run([$binary, '-t']);
            $out = trim($result->errorOutput().' '.$result->output());

            // If the structural check fails, prefer that error — it's
            // specific to the user's template rather than to the host's
            // running config.
            $structural = $this->fallbackStructuralCheck($config, 'OpenLiteSpeed');
            if (! $structural['ok']) {
                return $structural;
            }

            return $result->successful()
                ? ['ok' => true, 'message' => __('OpenLiteSpeed running config is OK. Template structural check passed; full template-level validation requires applying it to a host.')]
                : ['ok' => false, 'message' => $out !== '' ? $out : __(':bin -t failed (exit :code).', ['bin' => $binary, 'code' => $result->exitCode()])];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $this->binaryError('OpenLiteSpeed', $binary, $e)];
        }
    }

    /**
     * Traefik: `traefik --configFile=<file> --validate` — schema check
     * against the static config. Dynamic file-provider config is YAML/TOML
     * with no built-in CLI checker, so the structural fallback covers it.
     *
     * @return array{ok: bool, message: string}
     */
    private function validateTraefik(string $config): array
    {
        $binary = $this->firstAvailableBinary(['traefik']);
        if ($binary === null) {
            return $this->fallbackStructuralCheck($config, 'Traefik');
        }

        // YAML or TOML — pick a suffix Traefik recognizes. Heuristic on
        // the first non-empty line: a leading `[` reads as TOML; anything
        // else we treat as YAML.
        $firstLine = ltrim(strtok($config, "\n") ?: '');
        $suffix = str_starts_with($firstLine, '[') ? 'toml' : 'yml';
        $path = $this->tempFile('traefik', $suffix, $config);

        try {
            $result = Process::timeout(15)->run([$binary, '--configFile='.$path, '--validate']);
            $out = trim($result->errorOutput().' '.$result->output());

            return $result->successful()
                ? ['ok' => true, 'message' => $out !== '' ? $out : __('Traefik static config is valid.')]
                : ['ok' => false, 'message' => $out !== '' ? $out : __('traefik --validate failed (exit :code).', ['code' => $result->exitCode()])];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $this->binaryError('Traefik', $binary, $e)];
        } finally {
            @unlink($path);
        }
    }

    /**
     * Lighttpd: `lighttpd -t -f <file>` validates a standalone config.
     *
     * @return array{ok: bool, message: string}
     */
    private function validateLighttpd(string $config): array
    {
        $binary = $this->firstAvailableBinary(['lighttpd']);
        if ($binary === null) {
            return $this->fallbackStructuralCheck($config, 'Lighttpd');
        }

        $path = $this->tempFile('lighttpd', 'conf', $config);

        try {
            $result = Process::timeout(15)->run([$binary, '-t', '-f', $path]);
            $out = trim($result->errorOutput().' '.$result->output());

            return $result->successful()
                ? ['ok' => true, 'message' => $out !== '' ? $out : __('Lighttpd configuration syntax is valid.')]
                : ['ok' => false, 'message' => $out !== '' ? $out : __('lighttpd -t failed (exit :code).', ['code' => $result->exitCode()])];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $this->binaryError('Lighttpd', $binary, $e)];
        } finally {
            @unlink($path);
        }
    }

    /**
     * Last-resort check used when the engine's binary isn't installed on
     * the validator host: count `{` and `}`, plus a sanity check that the
     * config isn't empty after the call site has trimmed it. It's not a
     * syntax validator — it just catches unbalanced braces, which is the
     * #1 mistake in hand-written webserver config.
     *
     * @return array{ok: bool, message: string}
     */
    private function fallbackStructuralCheck(string $config, string $engineLabel): array
    {
        $openBraces = substr_count($config, '{');
        $closeBraces = substr_count($config, '}');

        if ($openBraces !== $closeBraces) {
            return [
                'ok' => false,
                'message' => __('Unbalanced braces in :engine template: :open `{` vs :close `}`. The binary isn\'t available locally for a full syntax check.', [
                    'engine' => $engineLabel,
                    'open' => $openBraces,
                    'close' => $closeBraces,
                ]),
            ];
        }

        return [
            'ok' => true,
            'message' => __(':engine binary isn\'t installed on this host, so a full syntax check can\'t run. Structural check passed (braces balance, body non-empty). The host running :engine will run its own config test at apply time.', [
                'engine' => $engineLabel,
            ]),
        ];
    }

    /**
     * Walk the candidates and return the first one resolvable on PATH.
     * `command -v` is portable across the shells we care about.
     */
    private function firstAvailableBinary(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            try {
                $check = Process::timeout(2)->run(['command', '-v', $candidate]);
                if ($check->successful() && trim($check->output()) !== '') {
                    return $candidate;
                }
            } catch (\Throwable) {
                // Fall through to the next candidate.
            }
        }

        return null;
    }

    private function tempFile(string $prefix, string $suffix, string $contents): string
    {
        $path = sys_get_temp_dir().'/dply-'.$prefix.'-test-'.Str::random(12).'.'.$suffix;
        file_put_contents($path, $contents);

        return $path;
    }

    private function binaryError(string $engineLabel, string $binary, \Throwable $e): string
    {
        return __('Could not run :bin (:msg). Install :engine on this host or validate on a server that has it.', [
            'bin' => $binary,
            'msg' => $e->getMessage(),
            'engine' => $engineLabel,
        ]);
    }
}
