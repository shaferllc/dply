<?php

declare(strict_types=1);

namespace App\Support\Debug;

use App\Events\Debug\TaskRunnerActivityBroadcast;
use App\Livewire\Debug\TaskRunnerPanel;
use App\Models\Server;
use App\Modules\TaskRunner\Contracts\StreamingLoggerInterface;
use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Bridges {@see StreamingLoggerInterface} events into the org-scoped Reverb
 * channel consumed by {@see TaskRunnerPanel}.
 *
 * Registered once at boot from {@see AppServiceProvider}. Sits
 * on the SSH/SCP/Process hot path so this MUST swallow every error — a
 * broadcast hiccup must not break the underlying remote command.
 */
final class TaskRunnerBroadcastBridge
{
    /**
     * Per-frame chunk cap so a chatty `process_output` line doesn't push
     * megabytes through Reverb.
     */
    private const PROCESS_OUTPUT_FRAME_CAP_BYTES = 8192;

    /**
     * Server → organization_id resolution cache TTL. Cheap because the
     * relation is immutable for the lifetime of a request.
     */
    private const SERVER_ORG_CACHE_TTL_SECONDS = 60;

    public static function register(StreamingLoggerInterface $logger): void
    {
        $logger->addStreamHandler(static function (array $logData): void {
            try {
                self::handle($logData);
            } catch (\Throwable $e) {
                Log::warning('TaskRunner debug bridge swallowed error', [
                    'message' => $e->getMessage(),
                    'class' => $e::class,
                ]);
            }
        });
    }

    /**
     * @param  array{timestamp: string, level: string, message: string, context: array<string, mixed>}  $logData
     */
    private static function handle(array $logData): void
    {
        $context = ($logData['context'] );
        $streamType = (string) ($context['stream_type'] ?? '');

        $kind = match ($streamType) {
            'task_event' => 'task.'.((string) ($context['event'] ?? 'unknown')),
            'process_output' => 'process.output',
            'error' => 'task.error',
            default => null,
        };

        if ($kind === null) {
            return;
        }

        $organizationId = self::resolveOrganizationId($context);
        if ($organizationId === null) {
            return;
        }

        $payload = self::buildPayload($kind, $logData, $context);

        TaskRunnerActivityBroadcast::dispatch($organizationId, $kind, $payload);
    }

    /**
     * @param  array<string, mixed> $context
     */
    private static function resolveOrganizationId(array $context): ?string
    {
        $serverId = $context['server_id'] ?? null;
        if (! is_string($serverId) || $serverId === '') {
            return null;
        }

        $key = 'taskrunner-bridge:server-org:'.$serverId;

        $resolved = Cache::remember($key, self::SERVER_ORG_CACHE_TTL_SECONDS, function () use ($serverId): ?string {
            $orgId = Server::query()
                ->whereKey($serverId)
                ->value('organization_id');

            return is_string($orgId) && $orgId !== '' ? $orgId : null;
        });

        return is_string($resolved) ? $resolved : null;
    }

    /**
     * @param  array{message: string, context: array<string, mixed>}  $logData
     * @param  array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function buildPayload(string $kind, array $logData, array $context): array
    {
        $base = [
            'server_id' => $context['server_id'] ?? null,
            'attempt' => $context['attempt'] ?? null,
            'command' => self::shrinkCommand($context['command'] ?? null),
        ];

        if ($kind === 'process.output') {
            return $base + [
                'type' => $context['type'] ?? 'out',
                'chunk' => self::truncate($logData['message'], self::PROCESS_OUTPUT_FRAME_CAP_BYTES),
            ];
        }

        if ($kind === 'task.completed') {
            return $base + [
                'exit_code' => $context['exit_code'] ?? null,
                'successful' => (bool) ($context['successful'] ?? false),
            ];
        }

        if ($kind === 'task.retrying') {
            return $base + [
                'delay' => $context['delay'] ?? null,
                'reason' => $context['reason'] ?? null,
            ];
        }

        if ($kind === 'task.error') {
            return $base + [
                'message' => self::truncate($logData['message'], self::PROCESS_OUTPUT_FRAME_CAP_BYTES),
                'max_attempts' => $context['max_attempts'] ?? null,
            ];
        }

        return $base;
    }

    /**
     * Trim the command string the bridge fans out — full argv is in the
     * persistent table when the operator clicks through to detail.
     */
    private static function shrinkCommand(mixed $command): ?string
    {
        if (is_array($command)) {
            $command = implode(' ', array_map(static fn ($v) => (string) $v, $command));
        }
        if (! is_string($command) || $command === '') {
            return null;
        }

        return self::truncate($command, 600);
    }

    private static function truncate(string $value, int $bytes): string
    {
        if (strlen($value) <= $bytes) {
            return $value;
        }

        return substr($value, 0, $bytes)."\n…[truncated]";
    }
}
