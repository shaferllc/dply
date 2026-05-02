<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Models;

use App\Models\Server;
use App\Models\User;
use App\Modules\TaskRunner\AnonymousTask;
use App\Modules\TaskRunner\Contracts\HasCallbacks;
use App\Modules\TaskRunner\Database\Factories\TaskFactory;
use App\Modules\TaskRunner\Enums\CallbackType;
use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Helper;
use App\Modules\TaskRunner\Jobs\UpdateTaskOutput;
use App\Modules\TaskRunner\TaskDispatcher;
use App\Support\Servers\FakeCloudProvision;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

/**
 * Task model for storing task execution data.
 */
class Task extends Model
{
    use HasFactory, HasUlids;

    protected $table = 'task_runner_tasks';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'action',
        'script',
        'script_content',
        'timeout',
        'user',
        'status',
        'output',
        'exit_code',
        'options',
        'instance',
        'server_id',
        'created_by',
        'started_at',
        'completed_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'exit_code' => 'integer',
        'timeout' => 'integer',
        'status' => TaskStatus::class,
        'options' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'instance',
    ];

    /**
     * Get the server that this task belongs to.
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * Create a new factory instance for the model.
     *
     * @return Factory<Task>
     */
    protected static function newFactory(): Factory
    {
        return TaskFactory::new();
    }

    /**
     * Get the user that created this task.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Check if the task is past its timeout.
     */
    public function isOlderThanTimeout(): bool
    {
        if (! $this->created_at) {
            return false;
        }

        return $this->created_at->copy()->addSeconds($this->timeout)->isPast();
    }

    /**
     * Get the path to the task's output file on the server.
     */
    public function outputLogPath(): string
    {
        $persistedPath = trim((string) ($this->options['remote_output_path'] ?? ''));
        if ($persistedPath !== '') {
            return $persistedPath;
        }

        if (! $this->server) {
            return '';
        }

        $directory = $this->user === 'root'
            ? $this->server->connectionAsRoot()->scriptPath
            : $this->server->connectionAsUser()->scriptPath;

        return "{$directory}/task-{$this->id}.log";
    }

    /**
     * Update the task output from the server.
     */
    public function updateOutput(bool $handleCallbacks = true): self
    {
        if (! $this->server) {
            // For local tasks, check if the background process has completed
            $this->updateLocalTaskStatus();

            return $this;
        }

        try {
            // Use the TaskRunner to get the output
            $getFileTask = AnonymousTask::command('Get Task Output', "cat {$this->outputLogPath()}");
            $pendingTask = $getFileTask->pending()->onConnection($this->server->connectionAsRoot());

            $output = app(TaskDispatcher::class)->run($pendingTask);

            if ($output && $output->isSuccessful()) {
                $this->update(['output' => $output->getBuffer()]);

                if ($handleCallbacks && $this->instance) {
                    $instance = unserialize($this->instance);
                    if ($instance && method_exists($instance, 'onOutputUpdated')) {
                        $instance->onOutputUpdated($output->getBuffer());
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error('Failed to update task output', [
                'task_id' => $this->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $this;
    }

    /**
     * Get the filtered output (without debug lines).
     */
    public function getOutput(): string
    {
        if (! $this->output) {
            return '';
        }

        $lines = [];
        $currentLines = preg_split('/\r\n|\r|\n/', $this->output);

        if ($currentLines === false) {
            return '';
        }

        foreach ($currentLines as $line) {
            if (! Str::startsWith($line, '+')) {
                $lines[] = $line;
            }
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * Update local task status by checking if the background process has completed.
     */
    private function updateLocalTaskStatus(): void
    {
        if ($this->status !== TaskStatus::Running) {
            return; // Only update running tasks
        }

        // Check if the output file exists and has content
        $outputPath = $this->getOutputPath();
        if (! $outputPath || ! file_exists($outputPath)) {
            return; // No output file yet
        }

        // Read the output file
        $output = file_get_contents($outputPath);
        if ($output === false) {
            return; // Couldn't read output file
        }

        // Update the output in the database
        $this->update(['output' => $output]);

        // Check if the process has completed by looking for the EOF marker
        $eofMarker = Helper::eof();
        if (str_contains($output, $eofMarker)) {
            // Process completed successfully
            $this->update([
                'status' => TaskStatus::Finished,
                'completed_at' => now(),
                'exit_code' => 0,
            ]);

            Log::info('Local task completed successfully', [
                'task_id' => $this->id,
                'task_name' => $this->name,
            ]);
        } else {
            // Check if the process has been running too long (timeout)
            $timeout = $this->getTimeout() ?? 600; // Default 10 minutes
            if ($this->started_at && $this->started_at->addSeconds($timeout)->isPast()) {
                $this->update([
                    'status' => TaskStatus::Timeout,
                    'completed_at' => now(),
                    'exit_code' => 124, // Timeout exit code
                ]);

                Log::warning('Local task timed out', [
                    'task_id' => $this->id,
                    'task_name' => $this->name,
                    'timeout' => $timeout,
                ]);
            }
        }
    }

    /**
     * Get the output path for local tasks.
     */
    private function getOutputPath(): ?string
    {
        // For local tasks (no server), use the storage path
        if (! $this->server) {
            return storage_path('logs/task-'.$this->id.'.log');
        }

        // For remote tasks, use the task's outputLogPath method
        if (! $this->instance) {
            return null;
        }

        try {
            $task = unserialize($this->instance);
            if (! $task || ! method_exists($task, 'outputLogPath')) {
                return null;
            }

            return $task->outputLogPath();
        } catch (\Exception $e) {
            Log::error('Failed to get output path for local task', [
                'task_id' => $this->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get the timeout for the task.
     */
    private function getTimeout(): ?int
    {
        if (! $this->instance) {
            return null;
        }

        try {
            $task = unserialize($this->instance);
            if (! $task || ! method_exists($task, 'getTimeout')) {
                return null;
            }

            return $task->getTimeout();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Update output without handling callbacks.
     */
    public function updateOutputWithoutCallbacks(): self
    {
        return $this->updateOutput(handleCallbacks: false);
    }

    /**
     * Update output in the background.
     */
    public function updateOutputInBackground(): self
    {
        UpdateTaskOutput::dispatch($this)
            ->onQueue('task-output');

        return $this;
    }

    /**
     * Check if there's output to fetch from the server.
     */
    public function hasOutputToFetch(): bool
    {
        if (! $this->isRunning()) {
            return false;
        }

        // For local tasks (no server), always return true to allow monitoring
        if (! $this->server) {
            return true;
        }

        try {
            // Check if the output file exists and has content
            $checkFileTask = AnonymousTask::command('Check Output File', "test -f {$this->outputLogPath()} && [ -s {$this->outputLogPath()} ]");
            $pendingTask = $checkFileTask->pending()->onConnection($this->server->connectionAsRoot());

            $output = app(TaskDispatcher::class)->run($pendingTask);

            return $output && $output->isSuccessful();
        } catch (\Exception $e) {
            Log::debug('Failed to check output file', [
                'task_id' => $this->id,
                'error' => $e->getMessage(),
            ]);

            // If we can't check, assume there might be output
            return true;
        }
    }

    /**
     * Get the output as a collection of lines.
     */
    public function outputLines(): Collection
    {
        if (! $this->output) {
            return collect();
        }

        return collect(explode(PHP_EOL, $this->output));
    }

    /**
     * Check if the task is finished.
     */
    public function isFinished(): bool
    {
        return $this->status === TaskStatus::Finished;
    }

    /**
     * Check if the task is pending.
     */
    public function isPending(): bool
    {
        return $this->status === TaskStatus::Pending;
    }

    /**
     * Check if the task is running.
     */
    public function isRunning(): bool
    {
        return $this->status === TaskStatus::Running;
    }

    /**
     * Check if the task has failed.
     */
    public function isFailed(): bool
    {
        return $this->status === TaskStatus::Failed;
    }

    /**
     * Check if the task has timed out.
     */
    public function isTimedOut(): bool
    {
        return $this->status === TaskStatus::Timeout;
    }

    /**
     * Check if the task was successful.
     */
    public function isSuccessful(): bool
    {
        return $this->isFinished() && $this->exit_code === 0;
    }

    /**
     * Get the last N lines of output.
     */
    public function tailOutput(int $lines = 10): string
    {
        $outputLines = $this->outputLines();
        $tailLines = $outputLines->take($lines * -1);

        return $tailLines->implode(PHP_EOL);
    }

    /**
     * Generate a signed URL for webhooks.
     */
    public function webhookUrl(string $name): string
    {
        $name = Str::kebab($name);
        $routeName = 'webhook.task.'.$name;

        // Fake-cloud servers run inside docker-compose.ssh-dev and reach the app via
        // extra_hosts → host gateway. The tunnel/public URL is irrelevant (and often
        // unreachable) from there, so anchor webhooks to APP_URL instead.
        $usePublicRoot = ! FakeCloudProvision::isFakeServer($this->server);

        $publicRoot = $usePublicRoot ? config('dply.public_app_url') : null;
        if (is_string($publicRoot) && $publicRoot !== '') {
            $publicRoot = rtrim($publicRoot, '/');
            $restoreRoot = rtrim((string) config('app.url'), '/');
            URL::forceRootUrl($publicRoot);
            try {
                return URL::signedRoute($routeName, ['task' => $this->id]);
            } finally {
                URL::forceRootUrl($restoreRoot !== '' ? $restoreRoot : null);
            }
        }

        return URL::signedRoute($routeName, ['task' => $this->id]);
    }

    /**
     * Get the timeout URL.
     */
    public function timeoutUrl(): string
    {
        return $this->webhookUrl('markAsTimedOut');
    }

    /**
     * Get the failed URL.
     */
    public function failedUrl(): string
    {
        return $this->webhookUrl('markAsFailed');
    }

    /**
     * Get the finished URL.
     */
    public function finishedUrl(): string
    {
        return $this->webhookUrl('markAsFinished');
    }

    /**
     * Get the callback URL.
     */
    public function callbackUrl(): string
    {
        return $this->webhookUrl('callback');
    }

    /**
     * Handle a callback for the task.
     */
    public function handleCallback(Request $request, CallbackType $type): void
    {
        if (! $this->instance) {
            return;
        }

        try {
            $instance = static::restoreStoredInstance($this->instance);
        } catch (Throwable $e) {
            Log::warning('Failed to restore task callback instance', [
                'task_id' => $this->id,
                'task_action' => $this->action,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if ($instance instanceof HasCallbacks) {
            $instance->handleCallback($this, $request, $type);
        }
    }

    public static function storeInstance(object $instance): string
    {
        return base64_encode(serialize($instance));
    }

    public static function restoreStoredInstance(string $storedInstance): mixed
    {
        $decoded = base64_decode($storedInstance, true);

        if ($decoded !== false) {
            return unserialize($decoded);
        }

        return unserialize($storedInstance);
    }

    /**
     * Get the task duration in seconds.
     */
    public function getDuration(): int
    {
        if (! $this->started_at) {
            return 0;
        }

        $endTime = $this->completed_at ?? now();

        return (int) $this->started_at->diffInSeconds($endTime);
    }

    /**
     * Get the task duration in a human-readable format.
     */
    public function getDurationForHumans(): string
    {
        $duration = $this->getDuration();

        if ($duration < 1) {
            return round($duration * 1000, 2).'ms';
        }

        if ($duration < 60) {
            return round($duration, 2).'s';
        }

        $minutes = floor($duration / 60);
        $seconds = $duration % 60;

        return "{$minutes}m ".round($seconds, 2).'s';
    }

    /**
     * Get the task performance metrics.
     */
    public function getPerformanceMetrics(): array
    {
        return [
            'task_id' => $this->id,
            'name' => $this->name,
            'status' => $this->status->value,
            'exit_code' => $this->exit_code,
            'duration' => $this->getDuration(),
            'duration_human' => $this->getDurationForHumans(),
            'started_at' => $this->started_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'output_size' => strlen($this->output ?? ''),
            'output_lines' => $this->outputLines()->count(),
            'successful' => $this->isSuccessful(),
        ];
    }

    /**
     * Get the task summary.
     */
    public function getSummary(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'action' => $this->action,
            'status' => $this->status->value,
            'exit_code' => $this->exit_code,
            'user' => $this->user,
            'timeout' => $this->timeout,
            'output_size' => strlen($this->output ?? ''),
            'output_lines' => $this->outputLines()->count(),
            'successful' => $this->isSuccessful(),
            'finished' => $this->isFinished(),
            'failed' => $this->isFailed(),
            'timed_out' => $this->isTimedOut(),
            'options' => $this->options,
            'performance' => $this->getPerformanceMetrics(),
        ];
    }
}
