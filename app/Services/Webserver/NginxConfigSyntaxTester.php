<?php

namespace App\Services\Webserver;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class NginxConfigSyntaxTester
{
    /**
     * Validate nginx config syntax with a real `nginx -t`, run inside a sandbox
     * prefix so the managed vhost's server-only dependencies (the
     * snippets/fastcgi-php.conf include, log paths, TLS certificate files) resolve
     * locally instead of failing on a workstation. This is a genuine syntax check
     * of the user's config — not a fake pass. If nginx isn't installed where the
     * app runs, it degrades to a structural brace/quote check so the editor still
     * gives feedback.
     *
     * @return array{ok: bool, message: string}
     */
    /** @return array<string, mixed> */
    public function testServerBlock(string $serverBlock): array
    {
        $banner = (string) config('webserver_templates.required_banner_line', '');
        $managedBanner = '# Managed by Dply';
        if ($banner !== '' && ! str_contains($serverBlock, $banner) && ! str_contains($serverBlock, $managedBanner)) {
            return [
                'ok' => false,
                'message' => __('Template must include this line: :line', ['line' => $banner]),
            ];
        }

        $serverBlock = trim($serverBlock);
        if ($serverBlock === '') {
            return ['ok' => false, 'message' => __('Template content is empty.')];
        }

        $serverBlock = $this->neutralizeServerOnlyDirectives($serverBlock);
        $serverBlock = trim($serverBlock);
        if ($serverBlock === '') {
            return ['ok' => false, 'message' => __('Template content is empty.')];
        }

        return $this->runSandboxedNginxTest($serverBlock);
    }

    /**
     * Wrap the server block in a minimal main config and run `nginx -t` against a
     * throwaway prefix seeded with stub includes. Falls back to a structural check
     * when the nginx binary can't be executed (e.g. not installed in dev).
     *
     * @return array{ok: bool, message: string}
     */
    private function runSandboxedNginxTest(string $serverBlock): array
    {
        $sandbox = sys_get_temp_dir().'/dply-nginx-sandbox-'.Str::random(10);
        $confFile = $sandbox.'/nginx.conf';
        $errLog = $sandbox.'/error.log';
        $pidFile = $sandbox.'/nginx.pid';

        // The 4 wrapper lines below sit ahead of the user's config. We subtract
        // them from nginx's reported line numbers so they map to what the user
        // submitted (neutralization is line-preserving, so the rest lines up).
        $wrapperOffset = 4;
        $wrapped = <<<NGINX
        pid {$pidFile};
        error_log {$errLog};
        events {}
        http {
        {$serverBlock}
        }
        NGINX;

        try {
            $this->seedSandbox($sandbox);
            file_put_contents($confFile, $wrapped);

            // -p sets the prefix so RELATIVE includes (snippets/fastcgi-php.conf,
            // fastcgi_params) resolve to our stubs; -e overrides the compiled-in
            // error-log path so nginx doesn't alert about /var/log being unwritable.
            $result = Process::timeout(15)->run(['nginx', '-t', '-p', $sandbox.'/', '-e', $errLog, '-c', $confFile]);

            // exit 127 / "command not found" => no nginx in PATH: degrade gracefully.
            if ($this->looksLikeMissingBinary($result->exitCode(), $result->errorOutput().$result->output())) {
                return $this->structuralFallback($serverBlock);
            }

            $out = $this->humanizeOutput($result->errorOutput().' '.$result->output(), $sandbox, $confFile, $wrapperOffset);

            if ($result->successful()) {
                return [
                    'ok' => true,
                    'message' => $out !== '' ? $out : __('Nginx configuration syntax is valid.'),
                ];
            }

            return [
                'ok' => false,
                'message' => $out !== '' ? $out : __('nginx -t failed with exit code :code.', ['code' => $result->exitCode()]),
            ];
        } catch (\Throwable $e) {
            // Binary missing / not runnable: structural fallback keeps the editor usable.
            return $this->structuralFallback($serverBlock);
        } finally {
            $this->removeSandbox($sandbox);
        }
    }

    /**
     * Create the sandbox prefix and write stub files for the server-only includes
     * the managed vhost references, so a relative `include` resolves during -t.
     */
    private function seedSandbox(string $sandbox): void
    {
        $snippets = $sandbox.'/snippets';
        if (! is_dir($snippets) && ! mkdir($snippets, 0700, true) && ! is_dir($snippets)) {
            throw new \RuntimeException('Could not create nginx validation sandbox.');
        }

        // Minimal but valid PHP-FPM snippet (mirrors the directives a real
        // snippets/fastcgi-php.conf provides) so configs that include it parse.
        file_put_contents($snippets.'/fastcgi-php.conf', <<<'CONF'
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        try_files $fastcgi_script_name =404;
        set $path_info $fastcgi_path_info;
        fastcgi_param PATH_INFO $path_info;
        fastcgi_index index.php;
        include fastcgi_params;
        CONF);

        // fastcgi_params: empty stub is syntactically valid where it's included.
        file_put_contents($sandbox.'/fastcgi_params', "# dply local-validation stub\n");
        // mime.types: in case a custom snippet includes it.
        file_put_contents($sandbox.'/mime.types', "types {\n}\n");
    }

    private function removeSandbox(string $sandbox): void
    {
        if (! is_dir($sandbox)) {
            return;
        }

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sandbox, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($sandbox);
    }

    /**
     * Strip/rewrite directives that depend on files only present on a real server
     * (logs, TLS certificates) so a local `nginx -t` exercises the config's syntax
     * without failing on missing host files. The user's actual directives —
     * locations, headers, rewrites, fastcgi, the before/main/after content — are
     * left intact and genuinely validated.
     */
    private function neutralizeServerOnlyDirectives(string $config): string
    {
        // Directives that reference host-only files (logs, TLS certs) — nginx -t
        // would fail trying to open them locally. Blank the line but KEEP it, so
        // line numbers in any error still match the config the user submitted.
        $dropLinePatterns = [
            '/^\s*access_log\s+[^;]*;\s*$/',
            '/^\s*error_log\s+[^;]*;\s*$/',
            '/^\s*open_log_file_cache\s+[^;]*;\s*$/',
            '/^\s*rewrite_log\s+[^;]*;\s*$/',
            '/^\s*ssl_certificate\s+[^;]*;\s*$/',
            '/^\s*ssl_certificate_key\s+[^;]*;\s*$/',
            '/^\s*ssl_trusted_certificate\s+[^;]*;\s*$/',
            '/^\s*ssl_dhparam\s+[^;]*;\s*$/',
        ];

        $lines = preg_split('/\R/', $config) ?: [];
        foreach ($lines as $i => $line) {
            foreach ($dropLinePatterns as $pattern) {
                if (preg_match($pattern, $line)) {
                    $lines[$i] = '';

                    continue 2;
                }
            }

            // Drop the `ssl` parameter from listen so no certificate is required;
            // the port stays (e.g. `listen 443;`) so there's no collision with :80.
            // `listen 443 ssl;`, `listen [::]:443 ssl http2;` → `listen 443;` etc.
            $lines[$i] = preg_replace('/^(\s*listen\s+\S+)\s+ssl\b[^;]*;/', '$1;', $line) ?? $line;
        }

        return implode("\n", $lines);
    }

    private function looksLikeMissingBinary(?int $exitCode, string $output): bool
    {
        // Array-form exec throws when the binary is absent (caught upstream); this
        // only catches the shell-execution case. Keep it narrow so a real nginx
        // error that happens to mention "No such file or directory" (e.g. a
        // missing include the user referenced) is NOT mistaken for a missing nginx.
        if ($exitCode === 127) {
            return true;
        }

        $needle = strtolower($output);

        return str_contains($needle, 'nginx: command not found')
            || str_contains($needle, 'nginx: not found');
    }

    /**
     * Tidy nginx -t output for display: hide the throwaway sandbox path and shift
     * reported line numbers back by the wrapper offset so they match the config
     * the user submitted (e.g. nginx ":9" → ":5").
     */
    private function humanizeOutput(string $output, string $sandbox, string $confFile, int $wrapperOffset): string
    {
        $label = __('your configuration');
        $output = str_replace($confFile, '('.$label.')', $output);
        // Strip the throwaway sandbox prefix from any other paths (e.g. an include
        // the user wrote) so they read as the relative path they actually typed.
        $output = str_replace(rtrim($sandbox, '/').'/', '', $output);

        $output = preg_replace_callback(
            '/\('.preg_quote($label, '/').'\):(\d+)/',
            fn (array $m): string => '('.$label.'):'.max(1, (int) $m[1] - $wrapperOffset),
            $output
        ) ?? $output;

        // Collapse runs of spaces/tabs but keep line breaks so nginx's two output
        // lines stay readable.
        $output = preg_replace('/[ \t]+/', ' ', $output) ?? $output;

        return trim((string) preg_replace('/\n{2,}/', "\n", $output));
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function structuralFallback(string $serverBlock): array
    {
        $result = $this->fakeStructuralCheck($serverBlock);
        if ($result['ok']) {
            $result['message'] = __('Structure looks valid (nginx not available here — install nginx for a full syntax check, or use “Validate on server”).');
        }

        return $result;
    }

    /**
     * Lightweight structural check used only when a real `nginx -t` can't run.
     * Reliably catches unbalanced braces and unterminated quotes (which nginx
     * rejects outright) without false-positiving on valid configs.
     *
     * @return array{ok: bool, message: string}
     */
    private function fakeStructuralCheck(string $serverBlock): array
    {
        // Strip comments so braces/quotes inside them don't trip us.
        $stripped = preg_replace('/#[^\n]*/', '', $serverBlock) ?? $serverBlock;

        $braces = 0;
        $inSingle = false;
        $inDouble = false;
        $len = strlen($stripped);
        for ($i = 0; $i < $len; $i++) {
            $ch = $stripped[$i];
            if ($ch === "'" && ! $inDouble) {
                $inSingle = ! $inSingle;
            } elseif ($ch === '"' && ! $inSingle) {
                $inDouble = ! $inDouble;
            } elseif (! $inSingle && ! $inDouble) {
                if ($ch === '{') {
                    $braces++;
                } elseif ($ch === '}') {
                    $braces--;
                    if ($braces < 0) {
                        return ['ok' => false, 'message' => __('Unbalanced braces: a closing “}” has no matching “{”.')];
                    }
                }
            }
        }

        if ($inSingle || $inDouble) {
            return ['ok' => false, 'message' => __('Unterminated quote in the configuration.')];
        }

        if ($braces !== 0) {
            return ['ok' => false, 'message' => __('Unbalanced braces: :count block(s) left open.', ['count' => $braces])];
        }

        return [
            'ok' => true,
            'message' => __('Structure looks valid.'),
        ];
    }
}
