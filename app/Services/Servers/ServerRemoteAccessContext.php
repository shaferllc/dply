<?php

declare(strict_types=1);

namespace App\Services\Servers;

use Illuminate\Support\Str;

/**
 * Request/job-scoped metadata for platform SSH access logging.
 */
final class ServerRemoteAccessContext
{
    /** @var array<string, string> */
    private array $eventIds = [];

    public bool $failed = false;

    public function __construct(
        public readonly string $source,
        public readonly string $label,
        public readonly ?string $userId = null,
        public readonly ?string $consoleActionId = null,
        public readonly ?string $jobUuid = null,
    ) {}

    public static function forJob(string $class, ?object $job = null, ?string $jobUuid = null): self
    {
        $basename = class_basename($class);
        $label = config('server_ssh_access.job_labels.'.$class)
            ?? config('server_ssh_access.job_labels.'.$basename)
            ?? Str::headline($basename);

        return new self(
            source: $basename,
            label: (string) $label,
            userId: self::extractUserId($job),
            jobUuid: $jobUuid,
        );
    }

    public static function forLivewireConsole(string $label, string $consoleActionId, ?string $userId): self
    {
        return new self(
            source: 'livewire',
            label: rtrim($label, " \t\n\r\0\x0B…"),
            userId: $userId,
            consoleActionId: $consoleActionId,
        );
    }

    public function rememberEvent(string $serverId, string $eventId): void
    {
        $this->eventIds[$serverId] = $eventId;
    }

    public function eventIdFor(string $serverId): ?string
    {
        return $this->eventIds[$serverId] ?? null;
    }

    /**
     * @return list<string>
     */
    public function eventIds(): array
    {
        return array_values($this->eventIds);
    }

    private static function extractUserId(?object $job): ?string
    {
        if ($job === null) {
            return null;
        }

        foreach (['userId', 'user_id', 'triggeredByUserId'] as $property) {
            if (! property_exists($job, $property)) {
                continue;
            }

            $value = $job->{$property};
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
