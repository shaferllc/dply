<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who receives each of the organization's email defaults.
 *
 * Recipients used to be decided at each send-site, differently: a deploy-finish
 * email went to the site owner plus every owner and admin in the org, while the
 * SSH-details and database-password emails went to the creator alone. This puts
 * the decision in one place so the settings screen can state it and change it.
 *
 * Recipients are always organization members. Deliberately not routed through
 * NotificationChannel: two of these three carry secrets — SSH host/port/user
 * and a plain-text database password — and a channel can point at Slack, a
 * webhook, or any address someone types. Non-secret events are already
 * subscribable that way; these are not.
 */
trait ManagesOrganizationEmailRecipients
{
    /** Only the user who created the thing. */
    public const RECIPIENTS_CREATOR = 'creator';

    /** The creator plus every owner and admin in the organization. */
    public const RECIPIENTS_ADMINS = 'admins';

    /** The creator plus a hand-picked set of members. */
    public const RECIPIENTS_CUSTOM = 'custom';

    /** Deploy-finish emails. */
    public const EMAIL_DEPLOY = 'deploy';

    /** SSH connection details when a server finishes provisioning. */
    public const EMAIL_SERVER_CREDENTIALS = 'server_credentials';

    /** Database credentials when a site is scaffolded or a database created. */
    public const EMAIL_DATABASE_CREDENTIALS = 'database_credentials';

    /**
     * Today's hardcoded behaviour, preserved so an org that has never touched
     * the setting sees no change.
     *
     * @var array<string, string>
     */
    public const EMAIL_RECIPIENT_DEFAULTS = [
        self::EMAIL_DEPLOY => self::RECIPIENTS_ADMINS,
        self::EMAIL_SERVER_CREDENTIALS => self::RECIPIENTS_CREATOR,
        self::EMAIL_DATABASE_CREDENTIALS => self::RECIPIENTS_CREATOR,
    ];

    /** @return list<string> */
    public static function emailRecipientKeys(): array
    {
        return array_keys(self::EMAIL_RECIPIENT_DEFAULTS);
    }

    /** @return list<string> */
    public static function emailRecipientModes(): array
    {
        return [self::RECIPIENTS_CREATOR, self::RECIPIENTS_ADMINS, self::RECIPIENTS_CUSTOM];
    }

    /**
     * The stored mode for one email key, falling back to its default.
     */
    public function emailRecipientMode(string $key): string
    {
        $prefs = $this->email_recipient_prefs ?? [];
        $mode = $prefs[$key]['mode'] ?? null;

        return in_array($mode, self::emailRecipientModes(), true)
            ? $mode
            : (self::EMAIL_RECIPIENT_DEFAULTS[$key] ?? self::RECIPIENTS_CREATOR);
    }

    /**
     * Hand-picked member ids for one email key. Only meaningful in `custom`
     * mode; membership is re-checked at send time, so a stale id from a
     * departed member simply drops out.
     *
     * @return list<string>
     */
    public function emailRecipientUserIds(string $key): array
    {
        $prefs = $this->email_recipient_prefs ?? [];
        $ids = $prefs[$key]['user_ids'] ?? [];

        return is_array($ids)
            ? array_values(array_filter(array_map('strval', $ids), fn (string $id): bool => $id !== ''))
            : [];
    }

    /**
     * Resolve one email key to the members who should receive it.
     *
     * The creator is always included when there is one: they performed the
     * action and, for the credential emails, they are the person who needs the
     * secret. Everything else is additive on top.
     *
     * @return Collection<int, User>
     */
    public function emailRecipients(string $key, ?User $creator = null): Collection
    {
        $mode = $this->emailRecipientMode($key);

        $ids = collect();
        if ($creator !== null) {
            $ids->push((string) $creator->id);
        }

        if ($mode === self::RECIPIENTS_ADMINS) {
            $ids = $ids->merge(
                $this->users()->wherePivotIn('role', ['owner', 'admin'])->pluck('users.id')
            );
        }

        if ($mode === self::RECIPIENTS_CUSTOM) {
            $ids = $ids->merge($this->emailRecipientUserIds($key));
        }

        $ids = $ids->map(fn ($id): string => (string) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        // Re-read through the membership pivot: a hand-picked id that has since
        // left the organization must not keep receiving its secrets.
        return $this->users()
            ->whereIn('users.id', $ids->all())
            ->get()
            ->filter(fn (User $user): bool => filled($user->email))
            ->values();
    }
}
