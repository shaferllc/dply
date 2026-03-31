<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Broadcasting;

use App\Modules\TaskRunner\Contracts\StreamingLoggerInterface;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;

class TaskRunnerBroadcaster
{
    /**
     * The channel name for task runner broadcasts.
     */
    public const CHANNEL = 'task-runner';

    /**
     * The private channel prefix for user-specific broadcasts.
     */
    public const PRIVATE_CHANNEL_PREFIX = 'private-task-runner';

    /**
     * Register the broadcasting handlers.
     */
    public function register(StreamingLoggerInterface $streamingLogger): void
    {
        if (! config('task-runner.logging.streaming.handlers.websocket', false)) {
            return;
        }

        $streamingLogger->addStreamHandler(function ($logData) {
            $this->broadcastLog($logData);
        }, 'websocket');

        $streamingLogger->addStreamHandler(function ($logData) {
            $this->broadcastTaskEvent($logData);
        }, 'task_event');

        $streamingLogger->addStreamHandler(function ($logData) {
            $this->broadcastProgress($logData);
        }, 'progress');
    }

    /**
     * Broadcast a log message to all connected clients.
     */
    public function broadcastLog(array $logData): void
    {
        try {
            Broadcast::to(self::CHANNEL)->emit('log', [
                'timestamp' => $logData['timestamp'],
                'level' => $logData['level'],
                'message' => $logData['message'],
                'context' => $logData['context'],
                'type' => 'log',
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to broadcast log', [
                'error' => $e->getMessage(),
                'log_data' => $logData,
            ]);
        }
    }

    /**
     * Broadcast a task event to all connected clients.
     */
    public function broadcastTaskEvent(array $logData): void
    {
        try {
            $eventData = [
                'timestamp' => $logData['timestamp'],
                'event' => $logData['context']['event'] ?? 'unknown',
                'task_id' => $logData['context']['task_id'] ?? null,
                'command' => $logData['context']['command'] ?? null,
                'message' => $logData['message'],
                'type' => 'task_event',
            ];

            Broadcast::to(self::CHANNEL)->emit('task-event', $eventData);

            // Also broadcast to task-specific channel if task_id is present
            if (! empty($logData['context']['task_id'])) {
                $taskChannel = self::CHANNEL.'.'.$logData['context']['task_id'];
                Broadcast::to($taskChannel)->emit('task-event', $eventData);
            }
        } catch (\Throwable $e) {
            Log::error('Failed to broadcast task event', [
                'error' => $e->getMessage(),
                'log_data' => $logData,
            ]);
        }
    }

    /**
     * Broadcast progress updates to all connected clients.
     */
    public function broadcastProgress(array $logData): void
    {
        try {
            $progressData = [
                'timestamp' => $logData['timestamp'],
                'current' => $logData['context']['current'] ?? 0,
                'total' => $logData['context']['total'] ?? 0,
                'percentage' => $logData['context']['percentage'] ?? 0,
                'message' => $logData['message'],
                'task_id' => $logData['context']['task_id'] ?? null,
                'type' => 'progress',
            ];

            Broadcast::to(self::CHANNEL)->emit('progress', $progressData);

            // Also broadcast to task-specific channel if task_id is present
            if (! empty($logData['context']['task_id'])) {
                $taskChannel = self::CHANNEL.'.'.$logData['context']['task_id'];
                Broadcast::to($taskChannel)->emit('progress', $progressData);
            }
        } catch (\Throwable $e) {
            Log::error('Failed to broadcast progress', [
                'error' => $e->getMessage(),
                'log_data' => $logData,
            ]);
        }
    }

    /**
     * Broadcast to a specific user's private channel.
     */
    public function broadcastToUser(int $userId, string $event, array $data): void
    {
        try {
            $channel = self::PRIVATE_CHANNEL_PREFIX.'.'.$userId;
            Broadcast::to($channel)->emit($event, $data);
        } catch (\Throwable $e) {
            Log::error('Failed to broadcast to user', [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'event' => $event,
                'data' => $data,
            ]);
        }
    }

    /**
     * Broadcast task metrics to all connected clients.
     */
    public function broadcastMetrics(array $metrics): void
    {
        try {
            Broadcast::to(self::CHANNEL)->emit('metrics', [
                'timestamp' => now()->toISOString(),
                'metrics' => $metrics,
                'type' => 'metrics',
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to broadcast metrics', [
                'error' => $e->getMessage(),
                'metrics' => $metrics,
            ]);
        }
    }

    /**
     * Broadcast task completion notification.
     */
    public function broadcastTaskCompleted(string $taskId, array $result): void
    {
        try {
            $completionData = [
                'timestamp' => now()->toISOString(),
                'task_id' => $taskId,
                'successful' => $result['successful'] ?? false,
                'exit_code' => $result['exit_code'] ?? null,
                'duration' => $result['duration'] ?? null,
                'type' => 'task_completed',
            ];

            Broadcast::to(self::CHANNEL)->emit('task-completed', $completionData);

            // Also broadcast to task-specific channel
            $taskChannel = self::CHANNEL.'.'.$taskId;
            Broadcast::to($taskChannel)->emit('task-completed', $completionData);
        } catch (\Throwable $e) {
            Log::error('Failed to broadcast task completion', [
                'error' => $e->getMessage(),
                'task_id' => $taskId,
                'result' => $result,
            ]);
        }
    }
}
