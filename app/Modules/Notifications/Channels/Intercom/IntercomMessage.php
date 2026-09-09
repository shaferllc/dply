<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Channels\Intercom;

/**
 * Fluent builder for an Intercom admin-initiated message (POST /messages).
 *
 * The public surface is a deliberate 1:1 copy of
 * laravel-notification-channels/intercom's IntercomMessage, so code written
 * against that package's documentation is a drop-in here. We reimplement rather
 * than depend because the package pins illuminate/* to ~9.0 and was last
 * released in Jan 2023 — it cannot install on Laravel 13.
 *
 * Everything below the "dply extensions" marker is ours and has no upstream
 * counterpart; upstream assumes a single app-wide token from config, whereas a
 * dply channel carries its own credential so each org talks to its own Intercom
 * workspace.
 *
 * @see https://developers.intercom.com/docs/references/rest-api/api.intercom.io/messages/createmessage
 */
class IntercomMessage
{
    public const TYPE_EMAIL = 'email';

    public const TYPE_INAPP = 'inapp';

    public const TEMPLATE_PLAIN = 'plain';

    public const TEMPLATE_PERSONAL = 'personal';

    public const REGION_US = 'us';

    public const REGION_EU = 'eu';

    public const REGION_AU = 'au';

    /** @var array<string, mixed> */
    public array $payload = [];

    /**
     * Credential overrides. Kept off $payload on purpose — $payload is posted to
     * Intercom verbatim, and an access token has no business in a request body.
     */
    protected ?string $accessToken = null;

    protected ?string $region = null;

    public function __construct(?string $body = null)
    {
        if ($body !== null) {
            $this->body($body);
        }

        $this->inapp();
    }

    public static function create(?string $body = null): self
    {
        return new self($body);
    }

    public function body(string $body): self
    {
        $this->payload['body'] = $body;

        return $this;
    }

    public function email(): self
    {
        $this->payload['message_type'] = self::TYPE_EMAIL;

        return $this;
    }

    public function inapp(): self
    {
        $this->payload['message_type'] = self::TYPE_INAPP;

        return $this;
    }

    public function subject(string $value): self
    {
        $this->payload['subject'] = $value;

        return $this;
    }

    public function plain(): self
    {
        $this->payload['template'] = self::TEMPLATE_PLAIN;

        return $this;
    }

    public function personal(): self
    {
        $this->payload['template'] = self::TEMPLATE_PERSONAL;

        return $this;
    }

    public function from(string $adminId): self
    {
        $this->payload['from'] = [
            'type' => 'admin',
            'id' => $adminId,
        ];

        return $this;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    public function to(array $value): self
    {
        $this->payload['to'] = $value;

        return $this;
    }

    public function toUserId(string $id): self
    {
        $this->payload['to'] = [
            'type' => 'user',
            'id' => $id,
        ];

        return $this;
    }

    public function toUserEmail(string $email): self
    {
        $this->payload['to'] = [
            'type' => 'user',
            'email' => $email,
        ];

        return $this;
    }

    public function toContactId(string $id): self
    {
        $this->payload['to'] = [
            'type' => 'contact',
            'id' => $id,
        ];

        return $this;
    }

    public function isValid(): bool
    {
        return isset(
            $this->payload['body'],
            $this->payload['from'],
            $this->payload['to']
        );
    }

    public function toIsGiven(): bool
    {
        return isset($this->payload['to']);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->payload;
    }

    // ---------------------------------------------------------------------
    // dply extensions — no upstream counterpart
    // ---------------------------------------------------------------------

    /**
     * Per-message access token. Set from a NotificationChannel's encrypted
     * config so each channel posts into its own Intercom workspace; when unset
     * the channel falls back to services.intercom.token.
     */
    public function token(string $accessToken): self
    {
        $this->accessToken = $accessToken;

        return $this;
    }

    public function getToken(): ?string
    {
        return $this->accessToken;
    }

    /**
     * Intercom hosts EU and AU workspaces on separate API domains, and a US
     * token simply 401s against them — so region travels with the credential.
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

    /**
     * Intercom's `to.type = 'email'` form, which upstream's builder omits.
     * Reaches a lead/contact by address without having resolved an id first.
     */
    public function toEmail(string $email): self
    {
        $this->payload['to'] = [
            'type' => 'email',
            'email' => $email,
        ];

        return $this;
    }

    /**
     * Open the conversation immediately rather than waiting for the contact to
     * reply. Only meaningful for `inapp`; Intercom ignores it on email.
     */
    public function createConversationWithoutContactReply(bool $value = true): self
    {
        $this->payload['create_conversation_without_contact_reply'] = $value;

        return $this;
    }

    /**
     * Which message_type this message will be sent as. Callers building a
     * message from stored channel config need this to decide whether a subject
     * is required.
     */
    public function messageType(): string
    {
        $type = $this->payload['message_type'] ?? self::TYPE_INAPP;

        return is_string($type) ? $type : self::TYPE_INAPP;
    }
}
