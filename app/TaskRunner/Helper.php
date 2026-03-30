<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Spatie\TemporaryDirectory\TemporaryDirectory;
use Throwable;

class Helper
{
    /**
     * Maximum allowed script size in bytes.
     */
    private const MAX_SCRIPT_SIZE = 1024 * 1024; // 1MB

    /**
     * Wraps the given script in a subshell using bash's here document.
     */
    public static function scriptInSubshell(string $script): string
    {
        if (empty(trim($script))) {
            throw new InvalidArgumentException('Script cannot be empty.');
        }

        if (strlen($script) > self::MAX_SCRIPT_SIZE) {
            throw new InvalidArgumentException('Script is too large (max '.(self::MAX_SCRIPT_SIZE / 1024).'KB).');
        }

        $eof = static::eof($script);

        return implode(PHP_EOL, [
            "'bash -s' << '{$eof}'",
            $script,
            $eof,
        ]);
    }

    /**
     * Returns a temporary directory that will be deleted on shutdown.
     */
    public static function temporaryDirectory(): TemporaryDirectory
    {
        try {
            $tempDir = config('task-runner.temporary_directory') ?: sys_get_temp_dir();

            // Validate the temporary directory
            if (! is_dir($tempDir) || ! is_writable($tempDir)) {
                throw new InvalidArgumentException("Temporary directory is not writable: {$tempDir}");
            }

            return tap(
                TemporaryDirectory::make($tempDir),
                fn ($temporaryDirectory) => register_shutdown_function(fn () => static::cleanupTemporaryDirectory($temporaryDirectory))
            );
        } catch (Throwable $e) {
            Log::error('Failed to create temporary directory', [
                'error' => $e->getMessage(),
                'temp_dir' => config('task-runner.temporary_directory'),
            ]);
            throw $e;
        }
    }

    /**
     * Returns the path to the temporary directory.
     */
    public static function temporaryDirectoryPath(string $pathOrFilename = ''): string
    {
        $path = static::temporaryDirectory()->path($pathOrFilename);

        // Validate the path for security
        static::validatePath($path);

        return $path;
    }

    /**
     * Use the nohup command to run a script in the background.
     */
    public static function scriptInBackground(string $scriptPath, ?string $outputPath = null, ?int $timeout = null): string
    {
        // Validate script path
        static::validatePath($scriptPath);

        if (! file_exists($scriptPath)) {
            throw new InvalidArgumentException("Script file does not exist: {$scriptPath}");
        }

        $outputPath = $outputPath ?: '/dev/null';
        $timeout = $timeout ?: 0;

        // Validate timeout
        if ($timeout < 0 || $timeout > 86400) { // Max 24 hours
            throw new InvalidArgumentException('Timeout must be between 0 and 86400 seconds.');
        }

        // Use a different approach that doesn't rely on nohup for web environments
        if ($timeout > 0) {
            // Use timeout with proper redirection that works in web environments
            return "timeout {$timeout}s bash {$scriptPath} > {$outputPath} 2>&1 &";
        } else {
            // Use bash with proper redirection and background execution
            return "bash {$scriptPath} > {$outputPath} 2>&1 &";
        }
    }

    /**
     * Returns the EOF string.
     */
    public static function eof(string $script = ''): string
    {
        if ($eof = config('task-runner.eof')) {
            return $eof;
        }

        return 'TASK-RUNNER-'.Hash::make($script);
    }

    /**
     * Validates a file path for security concerns.
     *
     * @throws InvalidArgumentException
     */
    private static function validatePath(string $path): void
    {
        // Check for path traversal attempts
        if (str_contains($path, '..') || str_contains($path, '//')) {
            throw new InvalidArgumentException('Path contains invalid characters.');
        }

        // Check for null bytes
        if (str_contains($path, "\0")) {
            throw new InvalidArgumentException('Path contains null bytes.');
        }

        // Check for overly long paths
        if (strlen($path) > 4096) {
            throw new InvalidArgumentException('Path is too long.');
        }
    }

    /**
     * Safely cleans up a temporary directory.
     */
    private static function cleanupTemporaryDirectory(TemporaryDirectory $directory): void
    {
        try {
            if ($directory->exists()) {
                $directory->delete();
            }
        } catch (Throwable $e) {
            // Log the error but don't throw to avoid disrupting shutdown
            Log::warning('Failed to cleanup temporary directory', [
                'path' => $directory->path(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Creates a secure temporary file with proper permissions.
     */
    public static function createSecureTempFile(string $content, string $extension = '.sh'): string
    {
        $tempFile = static::temporaryDirectoryPath(uniqid('task_', true).$extension);

        try {
            file_put_contents($tempFile, $content);
            chmod($tempFile, 0700); // Read/write/execute for owner only

            return $tempFile;
        } catch (Throwable $e) {
            // Clean up the file if it was created
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
            throw $e;
        }
    }

    /**
     * Safely removes a file with error handling.
     */
    public static function safeRemoveFile(string $path): bool
    {
        try {
            if (file_exists($path)) {
                return unlink($path);
            }

            return true;
        } catch (Throwable $e) {
            Log::warning('Failed to remove file', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Validates and sanitizes a script content.
     */
    public static function validateScriptContent(string $script): string
    {
        if (empty(trim($script))) {
            throw new InvalidArgumentException('Script content cannot be empty.');
        }

        if (strlen($script) > self::MAX_SCRIPT_SIZE) {
            throw new InvalidArgumentException('Script content is too large.');
        }

        // Check for potentially dangerous patterns
        $dangerousPatterns = [
            '/\$\{.*\}/', // Variable expansion
            '/\$\(.*\)/', // Command substitution
            '/`.*`/',     // Backtick command substitution
        ];

        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $script)) {
                throw new InvalidArgumentException('Script contains potentially dangerous patterns.');
            }
        }

        return $script;
    }
}
