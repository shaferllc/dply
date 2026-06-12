<?php

namespace App\Services\Notifications;

use App\Models\NotificationEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class NotificationPublisher
{
    public function __construct(
        private readonly NotificationEventRegistry $registry,
        private readonly ResourceNotificationContextResolver $contextResolver,
        private readonly NotificationRoutingResolver $routingResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $contextOverrides
     * @param  list<User|string>|null  $recipientUsers
     * @param  list<string>  $excludeChannelIds  NotificationChannel ULIDs to skip during routing.
     *                                           Use when the caller has already delivered the event
     *                                           through those channels directly (e.g. always-send
     *                                           fan-out for provision failure) and the subscription
     *                                           pipe would otherwise produce a duplicate message.
     * @param  list<string>  $excludeRecipientUserIds  User ULIDs to skip when fanning out the
     *                                                 in-app inbox. Use when the caller has already
     *                                                 delivered this event to those users via a sibling
     *                                                 publish (e.g. a site error's site-scoped dispatch
     *                                                 covers stakeholders the server roll-up would repeat).
     * @param  list<User|string>  $additionalRecipientUsers  Users always added to the in-app
     *                                                       recipient set (unioned on top of the resolved
     *                                                       stakeholders), e.g. the operator who triggered the
     *                                                       action — so they get the bell entry regardless of
     *                                                       subscription / stakeholder status.
     */
    public function publish(
        string $eventKey,
        ?Model $subject,
        string $title,
        ?string $body = null,
        ?string $url = null,
        array $metadata = [],
        array $contextOverrides = [],
        ?User $actor = null,
        ?array $recipientUsers = null,
        array $excludeChannelIds = [],
        array $excludeRecipientUserIds = [],
        array $additionalRecipientUsers = [],
    ): NotificationEvent {
        $definition = $this->registry->definition($eventKey);
        $context = array_replace($this->contextResolver->resolve($subject), $contextOverrides);
        $recipientUserIds = $recipientUsers !== null && $recipientUsers !== []
            ? $this->normalizeRecipientIds($recipientUsers)
            : $context['stakeholder_user_ids'];

        // Always-include recipients (e.g. the operator who triggered the action),
        // unioned on top of the resolved set so they get the in-app inbox item
        // regardless of subscription / stakeholder status.
        if ($additionalRecipientUsers !== []) {
            $recipientUserIds = array_values(array_unique(array_merge(
                $recipientUserIds,
                $this->normalizeRecipientIds($additionalRecipientUsers),
            )));
        }

        $event = NotificationEvent::query()->create([
            'event_key' => $eventKey,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject ? (string) $subject->getKey() : null,
            'resource_type' => $context['resource_type'],
            'resource_id' => $context['resource_id'],
            'organization_id' => $context['organization_id'],
            'team_id' => $context['team_id'],
            'actor_id' => $actor?->id,
            'title' => $title,
            'body' => $body,
            'url' => $url ?? $context['url'],
            'severity' => $definition['severity'],
            'category' => $definition['category'],
            'supports_in_app' => $definition['supports_in_app'],
            'supports_email' => $definition['supports_email'],
            'supports_webhook' => $definition['supports_webhook'],
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);

        $this->routingResolver->route($event, $recipientUserIds, $excludeChannelIds, $excludeRecipientUserIds);

        return $event;
    }

    /**
     * @param  list<User|string>  $recipientUsers
     * @return list<string>
     */
    private function normalizeRecipientIds(array $recipientUsers): array
    {
        return array_values(array_unique(array_map(
            static fn (User|string $user) => $user instanceof User ? (string) $user->getKey() : (string) $user,
            $recipientUsers
        )));
    }
}
