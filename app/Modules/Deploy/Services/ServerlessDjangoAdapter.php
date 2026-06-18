<?php

declare(strict_types=1);

namespace App\Modules\Deploy\Services;

use RuntimeException;

/**
 * Injects the DigitalOcean Functions ↔ Django adapter into a checked-out
 * Django project so it deploys as a single OpenWhisk web action.
 *
 * Django projects already ship a WSGI entrypoint — `<project>/wsgi.py`
 * exposes `application = get_wsgi_application()`. This adapter reuses the
 * shared WSGI handler ({@see ServerlessFlaskAdapter}) — it writes that
 * handler as the action's `__main__.py` entry, pointed at the project's
 * existing `wsgi.py`. Django's `wsgi.py` sets `DJANGO_SETTINGS_MODULE`
 * itself, so no extra wiring is needed.
 *
 * Two-step shape: {@see plan()} is pure and testable; {@see inject()}
 * performs the writes.
 */
class ServerlessDjangoAdapter
{
    /** OpenWhisk Python entry file the adapter is written as. */
    public const HANDLER_FILENAME = '__main__.py';

    /** OpenWhisk `exec.main` the deployer must point the action at. */
    public const HANDLER_FUNCTION = 'dplyMain';

    /**
     * Locate the project's WSGI entrypoint. Pure — reads files, writes nothing.
     *
     * @return array{django: bool, handler: string, function: string, module_file: string, app_var: string}
     */
    /** @return array<string, mixed> */
    public function plan(string $workingDirectory): array
    {
        $dir = rtrim($workingDirectory, '/');

        // Django's wsgi.py lives in the project package — one level down —
        // but a root-level wsgi.py is also valid.
        $candidates = glob($dir.'/*/wsgi.py') ?: [];
        if (is_file($dir.'/wsgi.py')) {
            $candidates[] = $dir.'/wsgi.py';
        }

        foreach ($candidates as $path) {
            $variable = $this->wsgiApplicationVariable((string) file_get_contents($path));
            if ($variable !== null) {
                return [
                    'django' => true,
                    'handler' => self::HANDLER_FILENAME,
                    'function' => self::HANDLER_FUNCTION,
                    'module_file' => ltrim(str_replace($dir, '', $path), '/'),
                    'app_var' => $variable,
                ];
            }
        }

        return [
            'django' => false,
            'handler' => self::HANDLER_FILENAME,
            'function' => self::HANDLER_FUNCTION,
            'module_file' => '',
            'app_var' => '',
        ];
    }

    /**
     * Write the adapter into the checked-out Django project. Throws when no
     * WSGI entrypoint can be found — a Django repo dply cannot wrap is a
     * deploy failure, not a silent no-op.
     *
     * @return array{django: bool, handler: string, function: string, module_file: string, app_var: string, ran: bool, output: string}
     */
    /** @return array<string, mixed> */
    public function inject(string $workingDirectory): array
    {
        $plan = $this->plan($workingDirectory);

        if (! $plan['django']) {
            throw new RuntimeException('dply Django adapter: no WSGI entrypoint found. A Django project must expose `application = get_wsgi_application()` in <project>/wsgi.py.');
        }

        $stub = $this->stubPath();
        if (! is_file($stub)) {
            throw new RuntimeException('DigitalOcean Functions WSGI adapter stub is missing: '.$stub);
        }

        $dir = rtrim($workingDirectory, '/');

        $handler = str_replace(
            ['{{DPLY_ENTRY}}', '{{DPLY_APP_VAR}}'],
            [$plan['module_file'], $plan['app_var']],
            (string) file_get_contents($stub),
        );
        if (file_put_contents($dir.'/'.self::HANDLER_FILENAME, $handler) === false) {
            throw new RuntimeException('Could not write the Django adapter to '.$dir.'/'.self::HANDLER_FILENAME);
        }

        return $plan + [
            'ran' => true,
            'output' => 'Injected DigitalOcean Functions Django adapter as '.self::HANDLER_FILENAME
                .', wrapping '.$plan['module_file'].':'.$plan['app_var'].'.',
        ];
    }

    /** The shared WSGI handler — Flask and Django use the same translation. */
    public function stubPath(): string
    {
        return resource_path('serverless/adapters/wsgi.py');
    }

    /**
     * Find the variable a Django WSGI application is assigned to —
     * `application = get_wsgi_application()` — or null when the file is not
     * a WSGI entrypoint.
     */
    private function wsgiApplicationVariable(string $source): ?string
    {
        if (preg_match('/^\s*([A-Za-z_]\w*)\s*=\s*get_wsgi_application\s*\(/m', $source, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
