<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceMember extends Model
{
    use HasUlids;

    public const ROLE_OWNER = 'owner';

    public const ROLE_MAINTAINER = 'maintainer';

    public const ROLE_DEPLOYER = 'deployer';

    public const ROLE_VIEWER = 'viewer';

    protected $fillable = [
        'workspace_id',
        'user_id',
        'role',
    ];

    public static function roles(): array
    {
        return [
            self::ROLE_OWNER,
            self::ROLE_MAINTAINER,
            self::ROLE_DEPLOYER,
            self::ROLE_VIEWER,
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
