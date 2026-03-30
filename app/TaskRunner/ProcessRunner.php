<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner;

use App\Modules\TaskRunner\Contracts\StreamingLoggerInterface;
use App\Modules\TaskRunner\Exceptions\TaskExecutionException;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\PendingProcess;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessRunner
{
    /**
     * Maximum number of retry attempts for failed processes.
     */
    private const MAX_RETRY_ATTEMPTS = 3;

    /**
     * Base delay between retries in seconds.
     */
    private const BASE_RETRY_DELAY = 1;

    /**
     * Create a new ProcessRunner instance.
     */
    public function __construct(
        protected readonly ?StreamingLoggerInterface $streamingLogger = null
    ) {
        // Only resolve from container if not provided and container is available
        if ($this->streamingLogger === null && app()->bound(StreamingLoggerInterface::class)) {
            $this->streamingLogger = app(StreamingLoggerInterface::class);
        }
    }

    /**
     * Runs the given process and waits for it to finish.
     *
     * @param  PendingProcess  $process  The process to run
     * @param  callable|null  $onOutput  Optional callback for handling output
     * @return ProcessOutput The process execution results
     *
     * @throws TaskExecutionException When process execution fails after all retries
     */
    public function run(PendingProcess $process, ?callable $onOutput = null): ProcessOutput
    {
        $attempt = 0;
        $lastException = null;

        // Stream task start event
        $this->streamTaskEvent('started', [
            'command' => $process->command,
            'attempt' => $attempt + 1,
        ]);

        while ($attempt < self::MAX_RETRY_ATTEMPTS) {
            try {
                return $this->executeProcessWithRetry($process, $onOutput, $attempt);
            } catch (ProcessTimedOutException $e) {
                $lastException = $e;
                $this->logRetryAttempt($process, $attempt, 'timeout', $e->getMessage());
                $this->streamError('Process timed out on attempt '.($attempt + 1), [
                    'command' => $process->command,
                    'attempt' => $attempt + 1,
                    'max_attempts' => self::MAX_RETRY_ATTEMPTS,
                ]);
            } catch (Throwable $e) {
                $lastException = $e;
                $this->logRetryAttempt($process, $attempt, 'error', $e->getMessage());
                $this->streamError('Process failed on attempt '.($attempt + 1), [
                    'command' => $process->command,
                    'attempt' => $attempt + 1,
                    'max_attempts' => self::MAX_RETRY_ATTEMPTS,
                    'error' => $e->getMessage(),
                ]);
            }

            $attempt++;

            if ($attempt < self::MAX_RETRY_ATTEMPTS) {
                $this->waitBeforeRetry($attempt);
            }
        }

        // All retries exhausted
        $this->streamError('Process execution failed after '.self::MAX_RETRY_ATTEMPTS.' attempts', [
            'command' => $process->command,
            'final_attempt' => $attempt,
        ]);

        throw new TaskExecutionException(
            'Process execution failed after '.self::MAX_RETRY_ATTEMPTS.' attempts',
            previous: $lastException
        );
    }

    /**
     * Executes the process with retry logic for a specific attempt.
     */
    private function executeProcessWithRetry(PendingProcess $process, ?callable $onOutput, int $attempt): ProcessOutput
    {
        $output = new ProcessOutput;

        // Create a combined output handler that handles both callbacks and streaming
        $combinedOutputHandler = function (string $type, string $buffer) use ($output, $onOutput, $process) {
            // Call the original output handler if provided
            if ($onOutput !== null) {
                $onOutput($type, $buffer);
            }

            // Stream the output in real-time
            $this->streamProcessOutput($type, $buffer, [
                'command' => $process->command,
            ]);

            // Update the ProcessOutput buffer
            $output($type, $buffer);
        };

        if ($onOutput !== null) {
            $output->onOutput($combinedOutputHandler);
        } else {
            $output->onOutput(function (string $type, string $buffer) {
                // Stream the output in real-time
                $this->streamProcessOutput($type, $buffer);
            });
        }

        return tap($output, function (ProcessOutput $output) use ($process, $attempt): void {
            try {
                $result = $this->executeProcess($process, $output);
                $this->updateOutputWithResult($output, $result, false);

                // Log successful execution
                $this->logProcessExecution($output, $result, false, $attempt);

                // Stream success event
                $this->streamTaskEvent('completed', [
                    'command' => $process->command,
                    'attempt' => $attempt + 1,
                    'exit_code' => $result->exitCode(),
                    'successful' => $result->exitCode() === 0,
                ]);

            } catch (ProcessTimedOutException $e) {
                $this->updateOutputWithResult($output, $e->result, true);
                $this->logProcessExecution($output, $e->result, true, $attempt);

                // Stream timeout event
                $this->streamError('Process timed out', [
                    'command' => $process->command,
                    'attempt' => $attempt + 1,
                    'timeout' => $process->timeout,
                ]);

                throw $e;
            }
        });
    }

    /**
     * Executes the process and returns the result.
     */
    private function executeProcess(PendingProcess $process, ProcessOutput $output): ProcessResult
    {
        return $process->run(output: $output);
    }

    /**
     * Updates the output object with the process result.
     */
    private function updateOutputWithResult(ProcessOutput $output, ProcessResult $result, bool $timeout): void
    {
        $output->setIlluminateResult($result);
        $output->setExitCode($result->exitCode());
        $output->setTimeout($timeout);
    }

    /**
     * Logs the process execution details.
     */
    private function logProcessExecution(ProcessOutput $output, ProcessResult $result, bool $timeout, int $attempt = 0): void
    {
        if (! config('task-runner.logging.enabled', true)) {
            return;
        }

        $logData = [
            'command' => $result->command(),
            'exit_code' => $result->exitCode(),
            'timed_out' => $timeout,
            'attempt' => $attempt + 1,
            'successful' => $result->exitCode() === 0 && ! $timeout,
        ];

        // Include output in logs if configured
        if (config('task-runner.logging.include_output', false)) {
            $logData['output'] = $output->getBuffer();
        }

        $logLevel = $result->exitCode() === 0 && ! $timeout ? 'info' : 'warning';

        Log::log($logLevel, 'Process executed', $logData);
    }

    /**
     * Logs retry attempt information.
     */
    private function logRetryAttempt(PendingProcess $process, int $attempt, string $reason, string $message): void
    {
        if (! config('task-runner.logging.enabled', true)) {
            return;
        }

        Log::warning('Process retry attempt', [
            'command' => $process->command,
            'attempt' => $attempt + 1,
            'max_attempts' => self::MAX_RETRY_ATTEMPTS,
            'reason' => $reason,
            'message' => $message,
        ]);
    }

    /**
     * Waits before retrying with exponential backoff.
     */
    private function waitBeforeRetry(int $attempt): void
    {
        $delay = self::BASE_RETRY_DELAY * (2 ** $attempt);

        // Cap the delay at 30 seconds
        $delay = min($delay, 30);

        // Stream retry delay information
        $this->streamTaskEvent('retrying', [
            'attempt' => $attempt + 1,
            'delay' => $delay,
            'reason' => 'Previous attempt failed',
        ]);

        sleep($delay);
    }

    /**
     * Checks if a process should be retried based on exit code.
     */
    private function shouldRetry(int $exitCode): bool
    {
        // Retry on non-zero exit codes, but not on specific error codes
        // that indicate permanent failures
        $permanentFailureCodes = [127, 126]; // Command not found, Permission denied

        return $exitCode !== 0 && ! in_array($exitCode, $permanentFailureCodes);
    }

    /**
     * Stream a task event.
     */
    private function streamTaskEvent(string $event, array $context = []): void
    {
        if ($this->streamingLogger && method_exists($this->streamingLogger, 'streamTaskEvent')) {
            $this->streamingLogger->streamTaskEvent($event, $context);
        }
    }

    /**
     * Stream process output.
     */
    private function streamProcessOutput(string $type, string $output, array $context = []): void
    {
        if ($this->streamingLogger && method_exists($this->streamingLogger, 'streamProcessOutput')) {
            $this->streamingLogger->streamProcessOutput($type, $output, $context);
        }
    }

    /**
     * Stream an error message.
     */
    private function streamError(string $message, array $context = []): void
    {
        if ($this->streamingLogger && method_exists($this->streamingLogger, 'streamError')) {
            $this->streamingLogger->streamError($message, $context);
        }
    }
}
