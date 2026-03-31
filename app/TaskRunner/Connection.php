<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner;

use App\Modules\TaskRunner\Exceptions\ConnectionNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class Connection
{
    public ?string $privateKeyPath = null;

    final public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $username,
        public readonly ?string $privateKey,
        public readonly ?string $scriptPath,
        public readonly ?string $proxyJump = null,
    ) {
        $this->validateConnection();
    }

    /**
     * Validates the connection parameters.
     *
     * @throws InvalidArgumentException
     */
    private function validateConnection(): void
    {
        $errors = [];

        // Validate host
        if (empty(trim($this->host))) {
            $errors[] = 'The host cannot be empty.';
        } elseif (! filter_var($this->host, FILTER_VALIDATE_DOMAIN) && ! filter_var($this->host, FILTER_VALIDATE_IP)) {
            $errors[] = 'The host must be a valid domain name or IP address.';
        } elseif (ctype_digit($this->host)) {
            $errors[] = 'The host cannot be only numeric.';
        } elseif (str_contains($this->host, '_')) {
            $errors[] = 'The host cannot contain underscores.';
        }

        // Validate port
        if ($this->port < 1 || $this->port > 65535) {
            $errors[] = 'The port must be between 1 and 65535.';
        }

        // Validate username
        if (empty(trim($this->username))) {
            $errors[] = 'The username cannot be empty.';
        } elseif (! preg_match('/^[a-zA-Z0-9._-]+$/', $this->username)) {
            $errors[] = 'The username contains invalid characters.';
        }

        // Validate private key
        if (empty(trim($this->privateKey ?? ''))) {
            $errors[] = 'The private key cannot be empty.';
        } elseif (! $this->isValidPrivateKey($this->privateKey)) {
            $errors[] = 'The private key format is invalid.';
        }

        // Validate script path
        if (empty(trim($this->scriptPath ?? ''))) {
            $errors[] = 'The script path cannot be empty.';
        } elseif (! $this->isValidScriptPath($this->scriptPath)) {
            $errors[] = 'The script path contains invalid characters.';
        }

        // Validate proxy jump if provided
        if ($this->proxyJump !== null && ! empty(trim($this->proxyJump))) {
            if (! $this->isValidProxyJump($this->proxyJump)) {
                $errors[] = 'The proxy jump format is invalid.';
            }
        }

        if (! empty($errors)) {
            throw new InvalidArgumentException('Connection validation failed: '.implode(' ', $errors));
        }
    }

    /**
     * Validates if the private key format is correct.
     */
    private function isValidPrivateKey(?string $privateKey): bool
    {
        if (empty($privateKey)) {
            return false;
        }

        $privateKey = trim((string) $privateKey);

        // Match typical PEM block for private keys (RSA, DSA, EC, OpenSSH, etc.)
        $pattern = '/^-----BEGIN (?:[A-Z ]+|OPENSSH) PRIVATE KEY-----[\r\n]+[A-Za-z0-9+\/="\r\n]+-----END (?:[A-Z ]+|OPENSSH) PRIVATE KEY-----$/s';

        return (bool) preg_match($pattern, $privateKey);
    }

    /**
     * Validates if the script path is safe.
     */
    private function isValidScriptPath(?string $scriptPath): bool
    {
        if (empty($scriptPath)) {
            return false;
        }

        // Check for path traversal attempts
        if (str_contains($scriptPath, '..') || str_contains($scriptPath, '//')) {
            return false;
        }

        // Check for null bytes
        if (str_contains($scriptPath, "\0")) {
            return false;
        }

        // Check for overly long paths
        if (strlen($scriptPath) > 4096) {
            return false;
        }

        return true;
    }

    /**
     * Validates if the proxy jump format is correct.
     */
    private function isValidProxyJump(string $proxyJump): bool
    {
        // Proxy jump format: user@host:port, host:port, user@[IPv6]:port, [IPv6]:port, user@host, host
        $pattern = '/^(?:[a-zA-Z0-9._-]+@)?(?:[a-zA-Z0-9._-]+|\[[0-9a-fA-F:]+\])(?::\d+)?$/';

        return preg_match($pattern, $proxyJump) === 1;
    }

    /**
     * Checks if the given connection is the same as this one.
     */
    public function is(Connection $connection): bool
    {
        return $this->host === $connection->host
            && $this->port === $connection->port
            && $this->username === $connection->username
            && $this->privateKey === $connection->privateKey
            && $this->scriptPath === $connection->scriptPath
            && $this->proxyJump === $connection->proxyJump;
    }

    /**
     * Creates a new connection from the given config connection name.
     *
     * @param  string  $connection  The config connection name.
     * @return Connection The created connection.
     *
     * @throws ConnectionNotFoundException If the connection is not found.
     */
    public static function fromConfig(string $connection): Connection
    {
        if (empty(trim($connection))) {
            throw new InvalidArgumentException('Connection name cannot be empty.');
        }

        /** @var array<string> $config The config array containing the connection details. */
        $config = config('task-runner.connections.'.$connection);

        if (! $config) {
            throw new ConnectionNotFoundException("Connection `{$connection}` not found.");
        }

        return static::fromArray($config);
    }

    /**
     * Creates a new connection from the given array.
     *
     * @param  array<string>  $config  The config array containing the connection details.
     * @return Connection The created connection.
     */
    public static function fromArray(array $config): Connection
    {
        try {
            $username = $config['username'] ?? '';

            $scriptPath = $config['script_path'] ?? null;

            if ($scriptPath !== null) {
                $scriptPath = rtrim($scriptPath, '/');
                if (trim($scriptPath) === '' && $config['script_path'] !== '/') {
                    throw new InvalidArgumentException('Script path cannot be empty.');
                }
                // If explicitly set to '/', allow it
                if ($config['script_path'] === '/') {
                    $scriptPath = '/';
                }
            }

            if (! $scriptPath && $username) {
                $scriptPath = $config['username'] === 'root'
                    ? '/root/.dply-task-runner'
                    : "/home/{$username}/.dply-task-runner";
            }

            $privateKey = $config['private_key'] ?? null;

            if (is_callable($privateKey)) {
                $privateKey = $privateKey();
            }

            $privateKeyPath = $config['private_key_path'] ?? null;

            if (! $privateKey && $privateKeyPath) {
                if (! file_exists($privateKeyPath)) {
                    throw new InvalidArgumentException("Private key file not found: {$privateKeyPath}");
                }
                if (! is_file($privateKeyPath)) {
                    throw new InvalidArgumentException("Private key path is not a file: {$privateKeyPath}");
                }
                if (! is_readable($privateKeyPath)) {
                    throw new InvalidArgumentException("Private key file is not readable: {$privateKeyPath}");
                }
                $privateKey = file_get_contents($privateKeyPath);
                if ($privateKey === false) {
                    throw new InvalidArgumentException("Failed to read private key file: {$privateKeyPath}");
                }
            }

            $instance = new static(
                host: $config['host'] ?? '',
                port: isset($config['port']) ? (int) $config['port'] : 22,
                username: $username,
                privateKey: $privateKey,
                scriptPath: $scriptPath,
                proxyJump: $config['proxy_jump'] ?? null,
            );

            if ($privateKeyPath) {
                $instance->setPrivateKeyPath($privateKeyPath);
            }

            return $instance;
        } catch (Throwable $e) {
            Log::error('Failed to create connection from array', [
                'config' => array_keys($config),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Sets the path to the private key.
     */
    public function setPrivateKeyPath(string $privateKeyPath): self
    {
        if (! file_exists($privateKeyPath)) {
            throw new InvalidArgumentException("Private key file not found: {$privateKeyPath}");
        }
        if (! is_file($privateKeyPath)) {
            throw new InvalidArgumentException("Private key path is not a file: {$privateKeyPath}");
        }
        if (! is_readable($privateKeyPath)) {
            throw new InvalidArgumentException("Private key file is not readable: {$privateKeyPath}");
        }
        $this->privateKeyPath = $privateKeyPath;

        return $this;
    }

    /**
     * Returns the path to the private key.
     */
    public function getPrivateKeyPath(): string
    {
        if ($this->privateKeyPath) {
            return $this->privateKeyPath;
        }

        return tap(
            $this->privateKeyPath = Helper::temporaryDirectoryPath(Str::random(32).'.key'),
            function () {
                try {
                    file_put_contents($this->privateKeyPath, $this->privateKey);

                    // Make sure the private key is only readable by the current user
                    chmod($this->privateKeyPath, 0600);
                } catch (Throwable $e) {
                    // Clean up the file if it was created
                    if (file_exists($this->privateKeyPath)) {
                        unlink($this->privateKeyPath);
                    }
                    throw $e;
                }
            }
        );
    }

    /**
     * Returns a string representation of the connection.
     */
    public function __toString(): string
    {
        return "{$this->username}@{$this->host}:{$this->port}";
    }
}
