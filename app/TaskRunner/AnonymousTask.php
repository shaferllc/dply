<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner;

use App\Modules\TaskRunner\Traits\HasProgressTracking;
use Closure;

class AnonymousTask extends Task
{
    use HasProgressTracking;

    /**
     * The task name.
     */
    protected string $name;

    /**
     * The task action.
     */
    protected string $action;

    /**
     * The task timeout.
     */
    protected ?int $timeout = 300;

    /**
     * The task view.
     */
    protected ?string $view;

    /**
     * The task data.
     */
    protected array $data = [];

    /**
     * The task script (if not using a view).
     */
    protected ?string $script = null;

    /**
     * The task render callback.
     */
    protected ?Closure $renderCallback = null;

    /**
     * Create a new AnonymousTask instance.
     */
    public function __construct(array $attributes = [])
    {
        // Set basic attributes
        $this->name = $attributes['name'] ?? 'Anonymous Task';
        $this->action = $attributes['action'] ?? 'anonymous';
        $this->timeout = isset($attributes['timeout']) && $attributes['timeout'] > 0
            ? $attributes['timeout']
            : null;
        $this->view = $attributes['view'] ?? null;
        $this->data = $attributes['data'] ?? [];
        $this->script = $attributes['script'] ?? null;
        $this->renderCallback = $attributes['render_callback'] ?? null;

        // Set public properties from data
        foreach ($this->data as $key => $value) {
            if (! property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }

    /**
     * Get the task name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get the task action.
     */
    public function getAction(): string
    {
        return $this->action;
    }

    /**
     * Get the task timeout.
     */
    public function getTimeout(): ?int
    {
        return $this->timeout;
    }

    /**
     * Get the task view.
     */
    public function getView(): string
    {
        if ($this->view !== null) {
            return $this->view;
        }

        return parent::getView();
    }

    /**
     * Get the task data.
     */
    public function getData(): array
    {
        return array_merge(parent::getData(), $this->data);
    }

    /**
     * Get the task view data.
     */
    public function getViewData(): array
    {
        return $this->data;
    }

    /**
     * Get the task script.
     */
    public function getScript(): string
    {
        // If a script is provided directly, use it
        if ($this->script !== null) {
            return $this->script;
        }

        // If a render callback is provided, use it
        if ($this->renderCallback !== null) {
            return call_user_func($this->renderCallback, $this);
        }

        // Otherwise, use the parent method (view rendering)
        return parent::getScript();
    }

    /**
     * Set the task name.
     */
    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Set the task action.
     */
    public function setAction(string $action): self
    {
        $this->action = $action;

        return $this;
    }

    /**
     * Set the task timeout.
     */
    public function setTimeout(?int $timeout): self
    {
        $this->timeout = $timeout;

        return $this;
    }

    /**
     * Set the task view.
     */
    public function setView(?string $view): self
    {
        $this->view = $view;

        return $this;
    }

    /**
     * Set the task script.
     */
    public function setScript(?string $script): self
    {
        $this->script = $script;

        return $this;
    }

    /**
     * Set the render callback.
     */
    public function setRenderCallback(?Closure $callback): self
    {
        $this->renderCallback = $callback;

        return $this;
    }

    /**
     * Add data to the task.
     */
    public function addData(array $data): self
    {
        $this->data = array_merge($this->data, $data);

        return $this;
    }

    /**
     * Set a single data value.
     */
    public function setData(string $key, mixed $value): self
    {
        $this->data[$key] = $value;

        return $this;
    }

    /**
     * Create an anonymous task with a script.
     */
    public static function script(string $name, string $script, array $options = []): self
    {
        return new self(array_merge([
            'name' => $name,
            'action' => 'script',
            'script' => $script,
        ], $options));
    }

    /**
     * Create an anonymous task with a view.
     */
    public static function view(string $name, string $view, array $data = [], array $options = []): self
    {
        return new self(array_merge([
            'name' => $name,
            'action' => 'view',
            'view' => $view,
            'data' => $data,
        ], $options));
    }

    /**
     * Create an anonymous task with a render callback.
     */
    public static function callback(string $name, Closure $callback, array $options = []): self
    {
        return new self(array_merge([
            'name' => $name,
            'action' => 'callback',
            'render_callback' => $callback,
        ], $options));
    }

    /**
     * Create an anonymous task with inline script.
     */
    public static function inline(string $name, string $script, array $options = []): self
    {
        return self::script($name, $script, $options);
    }

    /**
     * Create an anonymous task for a simple command.
     */
    public static function command(string $name, string $command, array $options = []): self
    {
        $script = "#!/bin/bash\nset -euo pipefail\n\n";
        $script .= "# {$name}\n";
        $script .= '# Generated: '.now()->toISOString()."\n\n";
        $script .= "echo 'Starting: {$name}'\n";
        $script .= "{$command}\n";
        $script .= "echo 'Completed: {$name}'\n";

        return self::script($name, $script, $options);
    }

    /**
     * Create an anonymous task for multiple commands.
     */
    public static function commands(string $name, array $commands, array $options = []): self
    {
        $script = "#!/bin/bash\nset -euo pipefail\n\n";
        $script .= "# {$name}\n";
        $script .= '# Generated: '.now()->toISOString()."\n\n";
        $script .= "echo 'Starting: {$name}'\n\n";

        foreach ($commands as $command) {
            $script .= "echo 'Executing: {$command}'\n";
            $script .= "{$command}\n";
            $script .= "echo 'Completed: {$command}'\n\n";
        }

        $script .= "echo 'Completed: {$name}'\n";

        return self::script($name, $script, $options);
    }

    /**
     * Create an anonymous task with environment variables.
     */
    public static function withEnv(string $name, array $env, string $command, array $options = []): self
    {
        $script = "#!/bin/bash\nset -euo pipefail\n\n";
        $script .= "# {$name}\n";
        $script .= '# Generated: '.now()->toISOString()."\n\n";

        // Set environment variables
        foreach ($env as $key => $value) {
            $script .= "export {$key}=\"{$value}\"\n";
        }

        $script .= "\necho 'Starting: {$name}'\n";
        $script .= "{$command}\n";
        $script .= "echo 'Completed: {$name}'\n";

        return self::script($name, $script, $options);
    }

    /**
     * Create an anonymous task with conditional logic.
     */
    public static function conditional(string $name, array $conditions, array $options = []): self
    {
        $script = "#!/bin/bash\nset -euo pipefail\n\n";
        $script .= "# {$name}\n";
        $script .= '# Generated: '.now()->toISOString()."\n\n";
        $script .= "echo 'Starting: {$name}'\n\n";

        foreach ($conditions as $condition => $commands) {
            $script .= "if {$condition}; then\n";
            if (is_array($commands)) {
                foreach ($commands as $command) {
                    $script .= "    {$command}\n";
                }
            } else {
                $script .= "    {$commands}\n";
            }
            $script .= "fi\n\n";
        }

        $script .= "echo 'Completed: {$name}'\n";

        return self::script($name, $script, $options);
    }

    /**
     * Create an anonymous task with error handling.
     */
    public static function withErrorHandling(string $name, string $command, ?string $errorCommand = null, array $options = []): self
    {
        $script = "#!/bin/bash\nset -euo pipefail\n\n";
        $script .= "# {$name}\n";
        $script .= '# Generated: '.now()->toISOString()."\n\n";
        $script .= "echo 'Starting: {$name}'\n\n";

        $script .= "if {$command}; then\n";
        $script .= "    echo 'Command completed successfully'\n";
        $script .= "else\n";
        $script .= "    echo 'Command failed'\n";
        if ($errorCommand) {
            $script .= "    {$errorCommand}\n";
        }
        $script .= "    exit 1\n";
        $script .= "fi\n\n";

        $script .= "echo 'Completed: {$name}'\n";

        return self::script($name, $script, $options);
    }

    /**
     * Create an anonymous task with retry logic.
     */
    public static function withRetry(string $name, string $command, int $maxRetries = 3, int $delay = 5, array $options = []): self
    {
        $script = "#!/bin/bash\nset -euo pipefail\n\n";
        $script .= "# {$name}\n";
        $script .= '# Generated: '.now()->toISOString()."\n\n";
        $script .= "echo 'Starting: {$name}'\n\n";

        $script .= "for attempt in \$(seq 1 {$maxRetries}); do\n";
        $script .= "    echo \"Attempt \$attempt of {$maxRetries}\"\n";
        $script .= "    if {$command}; then\n";
        $script .= "        echo 'Command completed successfully'\n";
        $script .= "        break\n";
        $script .= "    else\n";
        $script .= "        echo \"Attempt \$attempt failed\"\n";
        $script .= "        if [ \$attempt -lt {$maxRetries} ]; then\n";
        $script .= "            echo \"Waiting {$delay} seconds before retry...\"\n";
        $script .= "            sleep {$delay}\n";
        $script .= "        else\n";
        $script .= "            echo 'All attempts failed'\n";
        $script .= "            exit 1\n";
        $script .= "        fi\n";
        $script .= "    fi\n";
        $script .= "done\n\n";

        $script .= "echo 'Completed: {$name}'\n";

        return self::script($name, $script, $options);
    }

    /**
     * Create an anonymous task with progress tracking.
     */
    public static function withProgress(string $name, array $steps, array $options = []): self
    {
        $script = "#!/bin/bash\nset -euo pipefail\n\n";
        $script .= "# {$name}\n";
        $script .= '# Generated: '.now()->toISOString()."\n\n";
        $script .= "echo 'Starting: {$name}'\n\n";

        $totalSteps = count($steps);
        $currentStep = 0;

        foreach ($steps as $stepName => $stepCommand) {
            $currentStep++;
            $percentage = round(($currentStep / $totalSteps) * 100);

            $script .= "echo \"[PROGRESS] Step {$currentStep}/{$totalSteps} ({$percentage}%): {$stepName}\"\n";
            $script .= "{$stepCommand}\n";
            $script .= "echo \"[PROGRESS] Completed step {$currentStep}/{$totalSteps}\"\n\n";
        }

        $script .= "echo 'Completed: {$name}'\n";

        return self::script($name, $script, $options);
    }

    /**
     * Create an anonymous task with cleanup.
     */
    public static function withCleanup(string $name, string $command, string $cleanupCommand, array $options = []): self
    {
        $script = "#!/bin/bash\nset -euo pipefail\n\n";
        $script .= "# {$name}\n";
        $script .= '# Generated: '.now()->toISOString()."\n\n";
        $script .= "echo 'Starting: {$name}'\n\n";

        $script .= "trap 'echo \"Running cleanup...\"; {$cleanupCommand}' EXIT\n\n";
        $script .= "{$command}\n\n";
        $script .= "echo 'Completed: {$name}'\n";

        return self::script($name, $script, $options);
    }

    /**
     * Create an anonymous task with logging.
     */
    public static function withLogging(string $name, string $command, ?string $logFile = null, array $options = []): self
    {
        $logFile = $logFile ?? "/tmp/{$name}.log";

        $script = "#!/bin/bash\nset -euo pipefail\n\n";
        $script .= "# {$name}\n";
        $script .= '# Generated: '.now()->toISOString()."\n\n";
        $script .= "LOG_FILE=\"{$logFile}\"\n";
        $script .= "echo \"[$(date)] Starting: {$name}\" | tee -a \"\$LOG_FILE\"\n\n";
        $script .= "{$command} 2>&1 | tee -a \"\$LOG_FILE\"\n\n";
        $script .= "echo \"[$(date)] Completed: {$name}\" | tee -a \"\$LOG_FILE\"\n";

        return self::script($name, $script, $options);
    }
}
