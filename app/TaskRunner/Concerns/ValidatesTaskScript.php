<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Concerns;

use App\Modules\TaskRunner\Exceptions\TaskValidationException;
use App\Modules\TaskRunner\View\TaskViewRenderer;
use Illuminate\Container\Container;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ValidatesTaskScript
{


    /**
     * Validates the task before execution.
     *
     * @throws TaskValidationException
     */
    public function validate(): void
    {
        $errors = [];

        // Validate timeout
        $timeout = $this->getTimeout();
        if ($timeout !== null && ($timeout < 1 || $timeout > 3600)) {
            $errors['timeout'] = 'Timeout must be between 1 and 3600 seconds.';
        }

        // Validate view exists
        if (! method_exists($this, 'render') && ! view()->exists($this->getView())) {
            $errors['view'] = "View '{$this->getView()}' does not exist.";
        }

        // Validate public properties
        $this->getPublicProperties()->each(function ($value, $key) use (&$errors) {
            if (is_string($value) && strlen($value) > 10000) {
                $errors["property.{$key}"] = "Property '{$key}' value is too long (max 10000 characters).";
            }
        });

        if (! empty($errors)) {
            throw TaskValidationException::withErrors($errors);
        }
    }

    /**
     * Validates the generated script for security concerns.
     *
     * @throws TaskValidationException
     */
    protected function validateScript(string $script): void
    {
        $errors = [];

        // Check script size
        if (strlen($script) > self::MAX_SCRIPT_SIZE) {
            $errors['script_size'] = 'Script is too large (max '.(self::MAX_SCRIPT_SIZE / 1024).'KB).';
        }

        // Check for forbidden commands. The naive stripos() check this
        // replaced was too eager: forbidden 'rm -rf /' matched against
        // legitimate scoped removals like 'rm -rf /var/lib/mysql'
        // because it's a substring. Word-boundary matching is what we
        // actually want — match the forbidden command only when it
        // isn't extended by another word/path character. Boundary
        // class [\w/.-] catches the path-extending chars (var, /usr,
        // -rf followed by another -).
        $forbiddenCommands = config('task-runner.security.forbidden_commands', []);
        foreach ($forbiddenCommands as $command) {
            $pattern = '/(?:^|\s)'.preg_quote($command, '/').'(?![\w\/.\-])/i';
            if (preg_match($pattern, $script)) {
                $errors['forbidden_command'] = "Script contains forbidden command: {$command}";
                break;
            }
        }

        // Check for potentially dangerous patterns
        $dangerousPatterns = [
            '/\$\{.*\}/', // Variable expansion
            '/\$\(.*\)/', // Command substitution
            '/`.*`/',     // Backtick command substitution
        ];

        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $script)) {
                $errors['dangerous_pattern'] = 'Script contains potentially dangerous patterns.';
                break;
            }
        }

        if (! empty($errors)) {
            throw TaskValidationException::withErrors($errors);
        }
    }

    /**
     * Returns the rendered script.
     */
    public function getScript(): string
    {
        // Validate the task before generating script
        $this->validate();

        $script = '';

        try {
            if (method_exists($this, 'render')) {
                $script = Container::getInstance()->call([$this, 'render']);
            } else {
                // Use the enhanced view renderer for complex views
                $renderer = new TaskViewRenderer($this);
                $script = $renderer->render();
            }
        } catch (\Throwable $e) {
            throw new TaskValidationException(
                'Failed to generate script: '.$e->getMessage(),
                ['script_generation' => $e->getMessage()]
            );
        }

        // Validate the generated script
        $this->validateScript($script);

        return $script;
    }

    /**
     * Render the task's script as a string using the associated Blade view.
     * Useful for validation, preview, or testing.
     *
     * @param  array  $viewData  Optional overrides for view data.
     */
    protected function renderScript(array $viewData = []): string
    {
        $data = array_merge(
            $this->getData(),
            $viewData
        );

        return view($this->getView(), $data)->render();
    }
}
