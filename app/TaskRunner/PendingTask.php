<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner;

use App\Modules\TaskRunner\Exceptions\TaskValidationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Macroable;
use InvalidArgumentException;

class PendingTask
{
    use Conditionable;
    use Macroable;

    public ?Connection $connection = null;

    public ?string $connectionName = null;

    public bool $inBackground = false;

    public ?string $id = null;

    public ?string $outputPath = null;

    public ?string $action = null;

    public ?string $description = null;

    public ?int $timeout = null;

    /**
     * @var callable|null
     */
    public $onOutput = null;

    public function __construct(public readonly Task $task)
    {
        $this->validateTask();
    }

    /**
     * Validates the task instance.
     *
     * @throws InvalidArgumentException
     */
    protected function validateTask(): void
    {
        if (! $this->task instanceof Task) {
            throw new InvalidArgumentException('Task must be an instance of '.Task::class);
        }
    }

    /**
     * Wraps the given task in a PendingTask instance.
     */
    public static function make(string|Task|PendingTask $task): static
    {
        if (is_string($task)) {
            if (! class_exists($task)) {
                throw new InvalidArgumentException("Task class '{$task}' does not exist.");
            }

            if (! is_subclass_of($task, Task::class)) {
                throw new InvalidArgumentException("Class '{$task}' must extend ".Task::class);
            }

            $task = app($task);
        }

        return $task instanceof PendingTask ? $task : new PendingTask($task);
    }

    /**
     * A PHP callback to run whenever there is some output available on STDOUT or STDERR.
     */
    public function onOutput(callable $callback): self
    {
        if (! is_callable($callback)) {
            throw new InvalidArgumentException('Callback must be callable.');
        }

        $this->onOutput = $callback;

        return $this;
    }

    /**
     * Returns the callback that should be run whenever there is some output available on STDOUT or STDERR.
     */
    public function getOnOutput(): ?callable
    {
        return $this->onOutput;
    }

    /**
     * Exclude the onOutput closure from serialization.
     */
    public function __serialize(): array
    {
        $data = get_object_vars($this);
        unset($data['onOutput']);

        return $data;
    }

    public function __unserialize(array $data): void
    {
        foreach ($data as $key => $value) {
            $this->$key = $value;
        }
        $this->onOutput = null;
    }

    /**
     * Returns the connection, if set.
     */
    public function getConnection(): ?Connection
    {
        return $this->connection;
    }

    /**
     * Setter for the connection.
     */
    public function onConnection(string|Connection $connection): self
    {
        if (is_string($connection)) {
            if (empty(trim($connection))) {
                throw new InvalidArgumentException('Connection name cannot be empty.');
            }
            $this->connectionName = $connection;
            $this->connection = Connection::fromConfig($connection);
        } elseif ($connection instanceof Connection) {
            $this->connection = $connection;
        } else {
            throw new InvalidArgumentException('Connection must be a string or Connection instance.');
        }

        return $this;
    }

    /**
     * Checks if the task runs in the background.
     */
    public function shouldRunInBackground(): bool
    {
        return $this->inBackground;
    }

    /**
     * Checks if the task runs in the foreground.
     */
    public function shouldRunInForeground(): bool
    {
        return ! $this->inBackground;
    }

    /**
     * Sets the 'inBackground' property.
     */
    public function inBackground(bool $value = true): self
    {
        $this->inBackground = $value;

        return $this;
    }

    /**
     * Sets the 'inBackground' property to the opposite of the given value.
     */
    public function inForeground(bool $value = true): self
    {
        $this->inBackground = ! $value;

        return $this;
    }

    /**
     * Returns the 'id' property.
     */
    public function getId(): ?string
    {
        return $this->id;
    }

    /**
     * Sets the 'id' property.
     */
    public function id(?string $id = null): self
    {
        if ($id !== null && empty(trim($id))) {
            throw new InvalidArgumentException('Task ID cannot be empty.');
        }

        $this->id = $id;

        return $this;
    }

    /**
     * Alias for the 'id' method.
     */
    public function as(string $id): self
    {
        return $this->id($id);
    }

    /**
     * Set the ID for the pending task (fluent interface).
     */
    public function withId(string $id): self
    {
        $this->id = $id;

        return $this;
    }

    /**
     * Sets the action for this object.
     *
     * @param  string  $action  The action to set.
     * @return self The updated object.
     */
    public function setAction(string $action): self
    {
        if (empty(trim($action))) {
            throw new InvalidArgumentException('Action cannot be empty.');
        }

        $this->action = $action;

        return $this;
    }

    public function getAction(): ?string
    {
        return $this->action;
    }

    /**
     * Sets the description of the object.
     *
     * @param  string  $description  The description to set.
     * @return self Returns the updated object.
     */
    public function setDescription(string $description): self
    {
        if (empty(trim($description))) {
            throw new InvalidArgumentException('Description cannot be empty.');
        }

        $this->description = $description;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * Returns the 'outputPath' property.
     */
    public function getOutputPath(): ?string
    {
        return $this->outputPath;
    }

    /**
     * Sets the 'outputPath' property.
     */
    public function writeOutputTo(?string $outputPath = null): self
    {
        if ($outputPath !== null) {
            $this->validateOutputPath($outputPath);
        }

        $this->outputPath = $outputPath;

        return $this;
    }

    /**
     * Validates the output path for security concerns.
     *
     * @throws InvalidArgumentException
     */
    protected function validateOutputPath(string $path): void
    {
        // Check for path traversal attempts
        if (str_contains($path, '..') || str_contains($path, '//')) {
            throw new InvalidArgumentException('Invalid output path: contains path traversal characters.');
        }

        // Check for absolute paths outside allowed directories
        if (Str::startsWith($path, '/')) {
            $allowedDirs = [
                '/tmp',
                '/var/tmp',
                storage_path(),
                config('task-runner.temporary_directory', sys_get_temp_dir()),
            ];

            $isAllowed = false;
            foreach ($allowedDirs as $allowedDir) {
                if (Str::startsWith($path, $allowedDir)) {
                    $isAllowed = true;
                    break;
                }
            }

            if (! $isAllowed) {
                throw new InvalidArgumentException('Output path must be within allowed directories.');
            }
        }
    }

    /**
     * Checks if the given connection is the same as the connection of this task.
     */
    public function shouldRunOnConnection(bool|string|Connection|callable|null $connection = null): bool
    {
        if ($connection === null && $this->connection !== null) {
            return true;
        }

        if ($connection === true && $this->connection !== null) {
            return true;
        }

        if ($connection === false && $this->connection === null) {
            return true;
        }

        if (is_callable($connection)) {
            return $connection($this->connection) === true;
        }

        if (is_string($connection)) {
            $connection = Connection::fromConfig($connection);
        }

        if ($connection instanceof Connection) {
            return $connection->is($this->connection);
        }

        return false;
    }

    /**
     * Stores the script in a temporary directory and returns the path.
     */
    public function storeInTemporaryDirectory(): string
    {
        $id = $this->id ?: Str::random(32);
        $filename = "{$id}.sh";

        return tap(Helper::temporaryDirectoryPath($filename), function ($path) {
            try {
                $script = $this->task->getScript();

                // Additional security check for script content
                if (empty(trim($script))) {
                    throw new TaskValidationException('Generated script is empty.');
                }

                file_put_contents($path, $script);
                chmod($path, 0700);
            } catch (\Throwable $e) {
                // Clean up the file if it was created
                if (file_exists($path)) {
                    unlink($path);
                }
                throw $e;
            }
        });
    }

    /**
     * Dispatches the task to the given task runner.
     */
    public function dispatch(?TaskDispatcher $taskDispatcher = null): ?ProcessOutput
    {
        try {
            /** @var TaskDispatcher */
            $taskDispatcher = $taskDispatcher ?: app(TaskDispatcher::class);

            return $taskDispatcher->run($this);
        } catch (\Throwable $e) {
            // Log the error for debugging
            if (config('task-runner.logging.enabled', true)) {
                Log::error('Task dispatch failed', [
                    'task_class' => get_class($this->task),
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }

            throw $e;
        }
    }

    /**
     * Set the timeout for this task.
     */
    public function timeout(int $timeout): self
    {
        $this->timeout = $timeout;

        return $this;
    }

    /**
     * Get the timeout for this task.
     */
    public function getTimeout(): ?int
    {
        return $this->timeout ?? null;
    }

    public function getConnectionName(): ?string
    {
        return $this->connectionName;
    }
}
