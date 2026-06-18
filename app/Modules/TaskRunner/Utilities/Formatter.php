<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Utilities;

use App\Modules\TaskRunner\ProcessRunner;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/**
 * Formatter utility migrated from the Tasks module.
 * Provides formatting capabilities for various file types.
 */
class Formatter
{
    /**
     * Format the given bash script.
     */
    public function bash(string $script): string
    {
        return $this->handle($script, fn (string $path) => "beautysh {$path} -i 4");
    }

    /**
     * Format the given Caddyfile.
     */
    public function caddyfile(string $caddyfile): string
    {
        return $this->handle($caddyfile, fn (string $path) => "caddy fmt {$path} --overwrite");
    }

    /**
     * Format the given content with the command from the callback.
     */
    public function handle(string $content, callable $commandCallback): string
    {
        // Store the content in a temporary file using Spatie temporary directory
        $temporaryDirectory = TemporaryDirectory::make();
        $temporaryFile = $temporaryDirectory->path('beautify');

        file_put_contents($temporaryFile, $content);

        // Resolve the command
        $command = $commandCallback($temporaryFile);

        // Run the command and return the original content if it fails
        $output = (new ProcessRunner)->run(Process::command($command)->timeout(15));

        if (! $output->isSuccessful()) {
            $temporaryDirectory->delete();

            return $content;
        }

        // Get the formatted content from the temporary file
        $content = file_get_contents($temporaryFile);
        if ($content === false) {
            // Handle the error appropriately, again logging or throwing an exception
            throw new RuntimeException('Failed to get contents from the temporary file.');
        }

        $temporaryDirectory->delete();

        // Remove multiple empty lines
        $content = preg_replace('"(\r?\n){2,}"', "\n\n", $content);
        if ($content === null) {
            // Handle the error appropriately, for example, log it or throw an exception
            throw new RuntimeException('An error occurred during regex replacement.');
        }

        return $content;
    }
}
