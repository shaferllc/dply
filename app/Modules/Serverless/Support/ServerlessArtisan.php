<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Support;

use RuntimeException;

/**
 * Allowlisted, HMAC-signed artisan invocations against a Functions Laravel
 * adapter. Keep {@see ALLOWLIST} in sync with
 * `dply_do_functions_artisan_allowlist()` in the injected handler.
 */
final class ServerlessArtisan
{
    /**
     * @var list<string>
     */
    public const ALLOWLIST = [
        'about',
        'optimize', 'optimize:clear',
        'config:cache', 'config:clear',
        'route:cache', 'route:clear', 'route:list',
        'view:cache', 'view:clear',
        'event:cache', 'event:clear',
        'cache:clear',
        'queue:restart',
        'migrate', 'migrate:status',
        'down', 'up',
        'storage:link',
    ];

    public static function signature(string $secret, string $command): string
    {
        return hash_hmac('sha256', "artisan\n".$command, $secret);
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    public static function parse(string $command): array
    {
        $command = trim($command);
        if ($command === '' || preg_match('/[;|&`$()\\\\]|\n|\r/', $command) === 1) {
            throw new RuntimeException('Malformed artisan command.');
        }

        $parts = preg_split('/\s+/', $command) ?: [];
        $name = (string) array_shift($parts);
        if ($name === 'php') {
            $next = (string) array_shift($parts);
            if ($next !== 'artisan') {
                throw new RuntimeException('Artisan command is not allowlisted.');
            }
            $name = (string) array_shift($parts);
        } elseif ($name === 'artisan') {
            $name = (string) array_shift($parts);
        }

        if ($name === '' || ! in_array($name, self::ALLOWLIST, true)) {
            throw new RuntimeException('Artisan command is not allowlisted.');
        }

        $params = [];
        foreach ($parts as $part) {
            if (! str_starts_with($part, '--')) {
                throw new RuntimeException('Positional artisan arguments are not allowed.');
            }

            $opt = substr($part, 2);
            if ($opt === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9:_-]*(=.*)?$/', $opt) !== 1) {
                throw new RuntimeException('Malformed artisan option.');
            }

            if (str_contains($opt, '=')) {
                [$key, $value] = explode('=', $opt, 2);
                $params['--'.$key] = $value;
            } else {
                $params['--'.$opt] = true;
            }
        }

        if ($name === 'migrate' && ! array_key_exists('--force', $params)) {
            $params['--force'] = true;
        }

        return [$name, $params];
    }
}
