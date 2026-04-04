<?php

namespace App\Services\Servers;

/**
 * Builds a single-line `php artisan queue:work …` command for Supervisor.
 */
final class LaravelQueueWorkCommandBuilder
{
    public function __construct(
        public string $phpBinary = 'php',
        public string $connection = '',
        public string $queue = 'default',
        public int $timeout = 60,
        public int $sleep = 3,
        public int $tries = 3,
        public int $backoff = 0,
        public int $memory = 128,
        public int $maxTime = 3600,
    ) {}

    public function build(): string
    {
        $php = trim($this->phpBinary) !== '' ? trim($this->phpBinary) : 'php';
        $parts = [$php, 'artisan', 'queue:work'];

        $connection = trim($this->connection);
        if ($connection !== '') {
            $parts[] = escapeshellarg($connection);
        }

        $cmd = implode(' ', $parts);

        $cmd .= ' --queue='.self::shellEscapeArgValue($this->queue);
        $cmd .= ' --sleep='.max(0, $this->sleep);
        $cmd .= ' --timeout='.max(0, $this->timeout);
        $cmd .= ' --tries='.max(0, $this->tries);
        $cmd .= ' --memory='.max(16, $this->memory);
        $cmd .= ' --max-time='.max(0, $this->maxTime);

        if ($this->backoff > 0) {
            $cmd .= ' --backoff='.max(0, $this->backoff);
        }

        return $cmd;
    }

    /**
     * Escape a value for use after `--flag=` in a shell command line.
     */
    public static function shellEscapeArgValue(string $value): string
    {
        $value = trim($value);

        return escapeshellarg($value === '' ? 'default' : $value);
    }
}
