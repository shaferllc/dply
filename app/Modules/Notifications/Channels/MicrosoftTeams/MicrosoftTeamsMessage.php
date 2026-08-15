<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Channels\MicrosoftTeams;

/**
 * Fluent builder for a Microsoft Teams message, emitted as an **Adaptive Card**
 * inside a Power Automate Workflows envelope.
 *
 * This is the one integration in this namespace that deliberately does NOT
 * mirror its laravel-notification-channels package. That package builds
 * MessageCards for Office 365 connector webhooks, and Microsoft retired
 * connectors entirely between 18 and 22 May 2026 — a faithful port would be a
 * faithful port of something that no longer delivers.
 *
 * Method names still follow the package where the concept survived
 * (create/to/title/content/button/fact/summary/type), so the migration from
 * package-shaped code is mostly mechanical. What changed underneath is the
 * payload: MessageCard `sections`/`potentialAction` became Adaptive Card
 * `body`/`actions`, and `themeColor` became a per-element colour token, because
 * Adaptive Cards have no card-level colour.
 *
 * @see https://learn.microsoft.com/en-us/microsoftteams/platform/task-modules-and-cards/cards/cards-reference
 */
class MicrosoftTeamsMessage
{
    /**
     * Teams renders Adaptive Cards up to 1.5, but 1.4 is what every current
     * Teams client (desktop, web, mobile, Outlook surface) agrees on. Alerts are
     * not the place to chase a schema version.
     */
    public const SCHEMA_VERSION = '1.4';

    /** Adaptive Card colour tokens — not free-form hex, unlike MessageCard. */
    public const COLOR_DEFAULT = 'default';

    public const COLOR_ACCENT = 'accent';

    public const COLOR_GOOD = 'good';

    public const COLOR_WARNING = 'warning';

    public const COLOR_ATTENTION = 'attention';

    protected ?string $webhookUrl = null;

    protected ?string $title = null;

    protected ?string $summary = null;

    protected string $color = self::COLOR_DEFAULT;

    /** @var list<string> */
    protected array $content = [];

    /** @var list<array{title: string, value: string}> */
    protected array $facts = [];

    /** @var list<array{title: string, url: string}> */
    protected array $actions = [];

    public static function create(?string $content = null): self
    {
        $message = new static;

        if ($content !== null && $content !== '') {
            $message->content($content);
        }

        return $message;
    }

    /** The Workflows webhook URL this card posts to. */
    public function to(string $webhookUrl): self
    {
        $this->webhookUrl = $webhookUrl;

        return $this;
    }

    public function getWebhookUrl(): ?string
    {
        return $this->webhookUrl;
    }

    public function title(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    /**
     * Fallback text shown in the Teams activity feed and notification toast,
     * where the card itself isn't rendered. Without it Teams shows a bare
     * "sent a card", which is useless in a notification list.
     */
    public function summary(string $summary): self
    {
        $this->summary = $summary;

        return $this;
    }

    /**
     * Adaptive Card TextBlocks support a markdown subset — bold, italic, links,
     * bullets. Newlines are preserved by `wrap`.
     */
    public function content(string $content): self
    {
        if (trim($content) !== '') {
            $this->content[] = $content;
        }

        return $this;
    }

    /**
     * Accepts the package's semantic names. Hex is accepted and mapped to the
     * nearest token rather than silently dropped: Adaptive Cards have no
     * arbitrary colour, so honouring hex literally is not possible.
     */
    public function type(string $type): self
    {
        $this->color = match (mb_strtolower($type)) {
            'success', 'good' => self::COLOR_GOOD,
            'warning' => self::COLOR_WARNING,
            'error', 'danger', 'attention' => self::COLOR_ATTENTION,
            'primary', 'accent', 'info' => self::COLOR_ACCENT,
            default => self::COLOR_DEFAULT,
        };

        return $this;
    }

    public function color(string $color): self
    {
        $this->color = $color;

        return $this;
    }

    public function fact(string $name, string $value): self
    {
        if ($value !== '') {
            $this->facts[] = ['title' => $name, 'value' => $value];
        }

        return $this;
    }

    /**
     * @param  array<string, scalar|null>  $facts
     */
    public function facts(array $facts): self
    {
        foreach ($facts as $name => $value) {
            if ($value !== null && $value !== '') {
                $this->fact((string) $name, (string) $value);
            }
        }

        return $this;
    }

    public function button(string $text, string $url): self
    {
        if ($url !== '') {
            $this->actions[] = ['title' => $text, 'url' => $url];
        }

        return $this;
    }

    /** Package alias for {@see button()}; MessageCard called these actions. */
    public function action(string $text, string $url): self
    {
        return $this->button($text, $url);
    }

    public function isValid(): bool
    {
        return $this->title !== null || $this->content !== [];
    }

    /**
     * The Workflows envelope. A Workflows incoming webhook expects a `message`
     * with an `attachments` array — posting a bare Adaptive Card is accepted with
     * a 202 and then silently renders nothing, which is a miserable way to
     * discover the shape is wrong.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $body = [];

        if ($this->title !== null && $this->title !== '') {
            $body[] = [
                'type' => 'TextBlock',
                'text' => $this->title,
                'weight' => 'Bolder',
                'size' => 'Medium',
                'color' => $this->color,
                'wrap' => true,
            ];
        }

        foreach ($this->content as $paragraph) {
            $body[] = [
                'type' => 'TextBlock',
                'text' => $paragraph,
                'wrap' => true,
                'spacing' => 'Small',
            ];
        }

        if ($this->facts !== []) {
            $body[] = [
                'type' => 'FactSet',
                'facts' => $this->facts,
                'spacing' => 'Medium',
            ];
        }

        $card = [
            '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
            'type' => 'AdaptiveCard',
            'version' => self::SCHEMA_VERSION,
            'body' => $body,
        ];

        if ($this->actions !== []) {
            $card['actions'] = array_map(
                static fn (array $a): array => [
                    'type' => 'Action.OpenUrl',
                    'title' => $a['title'],
                    'url' => $a['url'],
                ],
                $this->actions
            );
        }

        // `msteams.width: Full` stops Teams rendering the card in a narrow
        // column that wraps every alert line.
        $card['msteams'] = ['width' => 'Full'];

        return [
            'type' => 'message',
            'summary' => $this->summary ?? $this->title ?? __('Notification'),
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'contentUrl' => null,
                'content' => $card,
            ]],
        ];
    }
}
