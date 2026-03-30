<?php

declare(strict_types=1);

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;
use Illuminate\Broadcasting\Channel;

/**
 * Decorator that broadcasts events to channels (Pusher, Redis, etc.).
 *
 * This decorator automatically broadcasts action results to configured channels
 * after successful execution. It supports custom channels, event names, and payloads.
 */
class BroadcastDecorator
{
    use DecorateActions;

    public function __construct($action)
    {
        $this->setAction($action);
    }

    public function handle(...$arguments)
    {
        $result = $this->callMethod('handle', $arguments);

        $this->broadcastEvent($result, $arguments);

        return $result;
    }

    protected function broadcastEvent($result, array $arguments): void
    {
        $channel = $this->getBroadcastChannel(...$arguments);
        $eventName = $this->getBroadcastEventName();
        $payload = $this->getBroadcastPayload($result, $arguments);

        if (! $channel) {
            return;
        }

        // Use Laravel's broadcasting
        if (class_exists(Channel::class)) {
            broadcast(new class($channel, $eventName, $payload) extends Channel
            {
                public function __construct(
                    public string $channelName,
                    public string $eventName,
                    public array $payload
                ) {
                    parent::__construct($channelName);
                }

                public function broadcastAs(): string
                {
                    return $this->eventName;
                }

                public function broadcastWith(): array
                {
                    return $this->payload;
                }
            });
        }
    }

    protected function getBroadcastChannel(...$arguments): ?string
    {
        if ($this->hasMethod('getBroadcastChannel')) {
            return $this->callMethod('getBroadcastChannel', $arguments);
        }

        return null;
    }

    protected function getBroadcastEventName(): string
    {
        if ($this->hasMethod('getBroadcastEventName')) {
            return $this->callMethod('getBroadcastEventName');
        }

        return class_basename($this->action);
    }

    protected function getBroadcastPayload($result, array $arguments): array
    {
        if ($this->hasMethod('getBroadcastPayload')) {
            return $this->callMethod('getBroadcastPayload', [$result, $arguments]);
        }

        return [
            'action' => get_class($this->action),
            'result' => $result,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
