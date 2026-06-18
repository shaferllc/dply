<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SecureShellKey
{
    /**
     * Create a new SSH key for a new user.
     */
    public static function forNewModel(string $password = '', string $username = 'robot'): object
    {
        self::ensureKeyDirectoryExists();

        return app()->environment(/* 'local', */ 'testing')
            ? static::forTesting()
            : static::make($password);
    }

    /**
     * Create a new SSH key.
     *
     * @throws \RuntimeException If key generation fails
     */
    public static function make(string $password = '', string $username = 'robot'): object
    {
        $name = Str::random(20);
        $privatePath = storage_path('app/sshkeys/'.$name);
        $publicPath = storage_path('app/sshkeys/'.$name.'.pub');

        $password = str_replace('\'', '\\\'', $password);

        try {
            $result = Process::path(storage_path('app/sshkeys'))
                ->env(['NAME' => $name, 'PASSWD' => $password])
                ->run('ssh-keygen -C "'.$username.'@dply.io" -f "$NAME" -t rsa -b 4096 -N "$PASSWD"');

            if (! $result->successful()) {
                throw new \RuntimeException('Failed to generate SSH key: '.$result->errorOutput());
            }

            if (! file_exists($privatePath) || ! file_exists($publicPath)) {
                throw new \RuntimeException('SSH key files were not created properly');
            }

            $publicKey = preg_replace("/\r|\n/", '', (string) file_get_contents($publicPath));
            $privateKey = (string) file_get_contents($privatePath);

            if (empty($publicKey) || empty($privateKey)) {
                throw new \RuntimeException('Generated SSH key files are empty');
            }

            $publicContent = explode(' ', (string) $publicKey, 3);
            if (count($publicContent) < 2) {
                throw new \RuntimeException('Invalid public key format');
            }

            $fingerprint = implode(':', str_split(Hash::make(base64_decode($publicContent[1])), 2));

            return (object) compact('publicKey', 'privateKey', 'fingerprint');
        } finally {
            // Always clean up the files
            if (file_exists($publicPath)) {
                @unlink($publicPath);
            }
            if (file_exists($privatePath)) {
                @unlink($privatePath);
            }
        }
    }

    /**
     * Store a secure shell key for the given user.
     */
    public static function storeFor(Model $model): string
    {
        return tap(
            storage_path('app/sshkeys/'.Str::random(20)),
            function ($path) use ($model) {
                static::ensureKeyDirectoryExists();

                static::ensureFileExists($path, $model->getAttribute('private_key'), 0600);
            }
        );
    }

    /**
     * Ensure the SSH key directory exists.
     *
     * @return void
     */
    protected static function ensureKeyDirectoryExists()
    {
        if (! is_dir(storage_path('app/sshkeys'))) {
            mkdir(storage_path('app/sshkeys'), 0755, true);
        }
    }

    /**
     * Ensure the given file exists.
     *
     * @return void
     */
    protected static function ensureFileExists(string $path, string $contents, int $chmod)
    {
        file_put_contents($path, $contents);

        chmod($path, $chmod);
    }

    /**
     * Create a new SSH key for testing.
     *
     * @throws InvalidArgumentException If key paths are invalid or files don't exist
     */
    protected static function forTesting(): object
    {
        $publicKeyPath = config('task-runner.test_ssh_container_public_key');
        $privateKeyPath = config('task-runner.test_ssh_container_key');

        if (! is_string($publicKeyPath) || ! is_string($privateKeyPath)) {
            throw new InvalidArgumentException('SSH key paths must be strings.');
        }

        if (! file_exists($publicKeyPath)) {
            throw new InvalidArgumentException("Public key file does not exist: {$publicKeyPath}");
        }

        if (! file_exists($privateKeyPath)) {
            throw new InvalidArgumentException("Private key file does not exist: {$privateKeyPath}");
        }

        $publicKey = file_get_contents($publicKeyPath);
        $privateKey = file_get_contents($privateKeyPath);

        if ($publicKey === false || $privateKey === false) {
            throw new InvalidArgumentException('Failed to read SSH key files.');
        }

        return (object) [
            'fingerprint' => 'foobar',
            'publicKey' => $publicKey,
            'privateKey' => $privateKey,
        ];
    }
}
