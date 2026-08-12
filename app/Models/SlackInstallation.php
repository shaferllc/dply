<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One "Add to Slack" install: a bot token for a single Slack workspace, owned by
 * a User, Organization or Team.
 *
 * Deliberately *not* one row per notification channel. The bot token can post to
 * any channel in the workspace, so a team connects Slack once and then picks
 * channels from a dropdown — rather than pasting a fresh incoming-webhook URL for
 * every alert destination they want.
 *
 * @property string $id
 * @property string $owner_type
 * @property string $owner_id
 * @property string $team_id
 * @property string $team_name
 * @property ?string $bot_user_id
 * @property ?string $scopes
 * @property array<string, mixed> $credentials
 * @property ?string $installed_by_user_id
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read Model $owner
 */
class SlackInstallation extends Model
{
    use HasUlids;

    protected $fillable = [
        'owner_type',
        'owner_id',
        'team_id',
        'team_name',
        'bot_user_id',
        'scopes',
        'credentials',
        'installed_by_user_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function botToken(): string
    {
        $token = $this->credentials['bot_token'] ?? null;

        return is_string($token) ? $token : '';
    }

    /**
     * Scopes granted at install time, as a list. A workspace connected before a
     * scope was added to the app keeps working for what it already had — the UI
     * uses this to decide whether to prompt for a re-auth.
     *
     * @return list<string>
     */
    public function grantedScopes(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->scopes))));
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->grantedScopes(), true);
    }
}
