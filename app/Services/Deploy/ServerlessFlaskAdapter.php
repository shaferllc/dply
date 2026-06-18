<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use RuntimeException;

/**
 * Injects the DigitalOcean Functions ↔ Flask adapter into a checked-out
 * Flask app so it deploys as a single OpenWhisk web action.
 *
 * The OpenWhisk-side counterpart to {@see DigitalOceanFunctionsLaravelAdapter}
 * for Python: the adapter becomes the action's `__main__.py` entry (the
 * Python runtime's entry file) and imports the repo's Flask app as a WSGI
 * application. Unlike the Express adapter, this needs no extra dependency —
 * WSGI is part of the standard library.
 *
 * Two-step shape: {@see plan()} is pure and testable; {@see inject()}
 * performs the writes.
 */
class ServerlessFlaskAdapter
{
    /** OpenWhisk Python entry file the adapter is written as. */
    public const HANDLER_FILENAME = '__main__.py';

    /** OpenWhisk `exec.main` the deployer must point the action at. */
    public const HANDLER_FUNCTION = 'dplyMain';

    /** Where the user's app module is moved if it collides with the adapter. */
    private const RENAMED_USER_FILE = '__dply_flask_app.py';

    /** Files that conventionally hold a Flask app, in search order. */
    private const APP_FILE_CANDIDATES = [
        'app.py', 'main.py', 'wsgi.py', 'application.py', 'server.py', '__main__.py',
    ];

    /**
     * Locate the repo's Flask app. Pure — reads files, writes nothing.
     *
     * @return array{flask: bool, handler: string, function: string, module_file: string, app_var: string}
     */
    /** @return array<string, mixed> */
    public function plan(string $workingDirectory): array
    {
        $dir = rtrim($workingDirectory, '/');

        foreach (self::APP_FILE_CANDIDATES as $candidate) {
            if (! is_file($dir.'/'.$candidate)) {
                continue;
            }

            $variable = $this->flaskAppVariable((string) file_get_contents($dir.'/'.$candidate));
            if ($variable !== null) {
                return [
                    'flask' => true,
                    'handler' => self::HANDLER_FILENAME,
                    'function' => self::HANDLER_FUNCTION,
                    'module_file' => $candidate,
                    'app_var' => $variable,
                ];
            }
        }

        return [
            'flask' => false,
            'handler' => self::HANDLER_FILENAME,
            'function' => self::HANDLER_FUNCTION,
            'module_file' => '',
            'app_var' => '',
        ];
    }

    /**
     * Write the adapter into the checked-out Flask app. Throws when no Flask
     * app object can be located — a Flask repo dply cannot wrap is a deploy
     * failure, not a silent no-op.
     *
     * @return array{flask: bool, handler: string, function: string, module_file: string, app_var: string, ran: bool, output: string}
     */
    /** @return array<string, mixed> */
    public function inject(string $workingDirectory): array
    {
        $plan = $this->plan($workingDirectory);

        if (! $plan['flask']) {
            throw new RuntimeException('dply Flask adapter: no Flask app object found. Expose one as `app = Flask(__name__)` in app.py, main.py, or wsgi.py.');
        }

        $stub = $this->stubPath();
        if (! is_file($stub)) {
            throw new RuntimeException('DigitalOcean Functions Flask adapter stub is missing: '.$stub);
        }

        $dir = rtrim($workingDirectory, '/');
        $moduleFile = $plan['module_file'];

        // The adapter takes the __main__.py entry slot. If the user's app
        // module already occupies that name, move it aside.
        $importTarget = $moduleFile;
        if ($moduleFile === self::HANDLER_FILENAME) {
            $importTarget = self::RENAMED_USER_FILE;
            rename($dir.'/'.$moduleFile, $dir.'/'.$importTarget);
        }

        $handler = str_replace(
            ['{{DPLY_ENTRY}}', '{{DPLY_APP_VAR}}'],
            [$importTarget, $plan['app_var']],
            (string) file_get_contents($stub),
        );
        if (file_put_contents($dir.'/'.self::HANDLER_FILENAME, $handler) === false) {
            throw new RuntimeException('Could not write the Flask adapter to '.$dir.'/'.self::HANDLER_FILENAME);
        }

        return $plan + [
            'ran' => true,
            'output' => 'Injected DigitalOcean Functions Flask adapter as '.self::HANDLER_FILENAME
                .', wrapping '.$importTarget.':'.$plan['app_var'].'.',
        ];
    }

    public function stubPath(): string
    {
        return resource_path('serverless/adapters/wsgi.py');
    }

    /**
     * Find the name a Flask app is assigned to — `app = Flask(__name__)` —
     * returning the variable, or null when the file holds no Flask app.
     */
    private function flaskAppVariable(string $source): ?string
    {
        if (preg_match('/^\s*([A-Za-z_]\w*)\s*=\s*Flask\s*\(/m', $source, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
