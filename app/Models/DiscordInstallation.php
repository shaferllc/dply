<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One "Add to Discord" install: the dply bot joined to a guild (server), owned
 * by a User, Organization or Team.
 *
 * Note the asymmetry with {@see SlackInstallation}, which holds a per-workspace
 * bot token. Discord issues one bot token per *application*, so this row holds
 * no secret at all — it records which guild the bot was admitted to, and the
 * token comes from deployment config. Consequence worth knowing: revoking a
 * connection means removing the bot from the guild, not rotating a credential.
 *
 * @property string $id
 * @property string $owner_type
 * @property string $owner_id
 * @property string $guild_id
 * @property string $guild_name
 * @property ?string $permissions
 * @property ?string $installed_by_user_id
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read Model $owner
 */
class DiscordInstallation extends Model
{
    use HasUlids;

    protected $fillable = [
        'owner_type',
        'owner_id',
        'guild_id',
        'guild_name',
        'permissions',
        'installed_by_user_id',
    ];

    /** Whether the granted permission bitfield includes both View Channels and Send Messages. */
    public function canPost(): bool
    {
        $granted = (int) $this->permissions;

        return ($granted & DiscordPermissions::REQUIRED) === DiscordPermissions::REQUIRED;
    }

    /** @return MorphTo<Model, $this> */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }
}
