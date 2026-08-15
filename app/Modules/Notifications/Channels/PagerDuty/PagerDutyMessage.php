<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Channels\PagerDuty;

use Illuminate\Support\Arr;

/**
 * Fluent builder for a PagerDuty Events API v2 event (POST /v2/enqueue).
 *
 * The public surface is a deliberate 1:1 copy of
 * laravel-notification-channels/pagerduty's PagerDutyMessage, so code written
 * against that package's documentation is a drop-in. We reimplement rather than
 * depend because the package caps at illuminate/* ^12.0 and this app is on
 * Laravel 13 — closer than the Intercom package, but still short.
 *
 * Note the two-bucket shape upstream uses and we preserve: `$meta` holds the
 * envelope (routing_key, event_action, dedup_key) and `$payload` holds the alert
 * body. toArray() collapses them with payload nested, which is exactly the
 * Events API v2 schema.
 *
 * Everything below the "dply extensions" marker is ours.
 *
 * @see https://developer.pagerduty.com/docs/events-api-v2-overview
 */
class PagerDutyMessage
{
    public const EVENT_TRIGGER = 'trigger';

    public const EVENT_ACKNOWLEDGE = 'acknowledge';

    public const EVENT_RESOLVE = 'resolve';

    public const SEVERITY_CRITICAL = 'critical';

    public const SEVERITY_ERROR = 'error';

    public const SEVERITY_WARNING = 'warning';

    public const SEVERITY_INFO = 'info';

    public const REGION_US = 'us';

    public const REGION_EU = 'eu';

    /**
     * PagerDuty truncates a longer summary itself; capping here keeps the
     * request honest and the incident title readable.
     */
    public const SUMMARY_MAX_LENGTH = 1024;

    /** @var array<string, mixed> */
    protected array $payload = [];

    /** @var array<string, mixed> */
    protected array $meta = [];

    /** Which PagerDuty service region to enqueue against. Not part of the body. */
    protected ?string $region = null;

    public function __construct()
    {
        Arr::set($this->meta, 'event_action', self::EVENT_TRIGGER);
        // Upstream defaults source to the machine's hostname. Kept for parity —
        // callers that know the real subject (a server, a site) should override
        // it, because the web node's hostname is rarely the thing that broke.
        Arr::set($this->payload, 'source', gethostname());
        Arr::set($this->payload, 'severity', self::SEVERITY_CRITICAL);
    }

    public static function create(): self
    {
        return new static;
    }

    public function setRoutingKey(string $value): self
    {
        return $this->setMeta('routing_key', $value);
    }

    public function resolve(): self
    {
        return $this->setMeta('event_action', self::EVENT_RESOLVE);
    }

    public function setDedupKey(string $key): self
    {
        return $this->setMeta('dedup_key', $key);
    }

    public function setSummary(string $value): self
    {
        return $this->setPayload('summary', mb_substr($value, 0, self::SUMMARY_MAX_LENGTH));
    }

    public function setSource(string $value): self
    {
        return $this->setPayload('source', $value);
    }

    public function setSeverity(string $value): self
    {
        return $this->setPayload('severity', $value);
    }

    public function setTimestamp(string $value): self
    {
        return $this->setPayload('timestamp', $value);
    }

    public function setComponent(string $value): self
    {
        return $this->setPayload('component', $value);
    }

    public function setGroup(string $value): self
    {
        return $this->setPayload('group', $value);
    }

    public function setClass(string $value): self
    {
        return $this->setPayload('class', $value);
    }

    public function addCustomDetail(string $key, string $value): self
    {
        return $this->setPayload("custom_details.$key", $value);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return Arr::collapse([$this->meta, ['payload' => $this->payload]]);
    }

    // ---------------------------------------------------------------------
    // dply extensions — no upstream counterpart
    // ---------------------------------------------------------------------

    /**
     * Suppress an incident without resolving it. Events API v2 supports this
     * alongside trigger/resolve; upstream's builder only exposes the other two.
     */
    public function acknowledge(): self
    {
        return $this->setMeta('event_action', self::EVENT_ACKNOWLEDGE);
    }

    public function trigger(): self
    {
        return $this->setMeta('event_action', self::EVENT_TRIGGER);
    }

    public function eventAction(): string
    {
        $action = $this->meta['event_action'] ?? self::EVENT_TRIGGER;

        return is_string($action) ? $action : self::EVENT_TRIGGER;
    }

    public function getDedupKey(): ?string
    {
        $key = $this->meta['dedup_key'] ?? null;

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * PagerDuty serves EU accounts from a separate host; a US routing key is
     * rejected there and vice versa.
     */
    public function region(string $region): self
    {
        $this->region = $region;

        return $this;
    }

    public function getRegion(): ?string
    {
        return $this->region;
    }

    public function getRoutingKey(): ?string
    {
        $key = $this->meta['routing_key'] ?? null;

        return is_string($key) && $key !== '' ? $key : null;
    }

    /** Name of the system posting the event — shown on the incident. */
    public function setClient(string $value): self
    {
        return $this->setMeta('client', $value);
    }

    /** Link back to the system posting the event. */
    public function setClientUrl(string $value): self
    {
        return $this->setMeta('client_url', $value);
    }

    public function addLink(string $href, ?string $text = null): self
    {
        $links = $this->meta['links'] ?? [];
        $links = is_array($links) ? $links : [];
        $links[] = array_filter(['href' => $href, 'text' => $text], static fn ($v) => $v !== null);

        return $this->setMeta('links', $links);
    }

    public function addImage(string $src, ?string $href = null, ?string $alt = null): self
    {
        $images = $this->meta['images'] ?? [];
        $images = is_array($images) ? $images : [];
        $images[] = array_filter(['src' => $src, 'href' => $href, 'alt' => $alt], static fn ($v) => $v !== null);

        return $this->setMeta('images', $images);
    }

    /**
     * Bulk form of addCustomDetail(). Scalars only — Events API v2 renders
     * custom_details as a flat key/value table on the incident.
     *
     * @param  array<string, scalar|null>  $details
     */
    public function setCustomDetails(array $details): self
    {
        foreach ($details as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $this->addCustomDetail($key, (string) $value);
        }

        return $this;
    }

    /**
     * A trigger needs summary + source + severity; a resolve/acknowledge needs
     * only the dedup_key naming the incident to act on. Checked before sending
     * so a malformed event fails with our message rather than a bare 400.
     */
    public function isValid(): bool
    {
        if ($this->eventAction() !== self::EVENT_TRIGGER) {
            return $this->getDedupKey() !== null;
        }

        return isset($this->payload['summary'], $this->payload['source'], $this->payload['severity'])
            && $this->payload['summary'] !== '';
    }

    /**
     * @return list<string>
     */
    public static function severities(): array
    {
        return [self::SEVERITY_CRITICAL, self::SEVERITY_ERROR, self::SEVERITY_WARNING, self::SEVERITY_INFO];
    }

    /**
     * Map a dply NotificationEvent severity onto a PagerDuty one. dply's
     * vocabulary is close but not identical, and an unrecognised value must not
     * become `critical` by accident.
     */
    public static function severityFromEventSeverity(?string $severity): string
    {
        return match (mb_strtolower((string) $severity)) {
            'critical', 'fatal' => self::SEVERITY_CRITICAL,
            'error', 'danger', 'failure', 'failed' => self::SEVERITY_ERROR,
            'warning', 'warn' => self::SEVERITY_WARNING,
            default => self::SEVERITY_INFO,
        };
    }

    protected function setPayload(string $key, mixed $value): self
    {
        Arr::set($this->payload, $key, $value);

        return $this;
    }

    protected function setMeta(string $key, mixed $value): self
    {
        Arr::set($this->meta, $key, $value);

        return $this;
    }
}
