<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $server_id
 * @property string $unit
 * @property ?string $label
 * @property ?string $active_state
 * @property ?string $sub_state
 * @property ?string $unit_file_state
 * @property ?int $main_pid
 * @property ?string $active_enter_ts
 * @property ?int $version
 * @property bool $is_custom
 * @property bool $can_manage
 * @property ?\Illuminate\Support\Carbon $captured_at
 * @property ?string $pending_action
 * @property ?\Illuminate\Support\Carbon $pending_action_at
 * @property-read Server $server
 */
class ServerSystemdServiceState extends Model
{
    protected $fillable = [
        'server_id',
        'unit',
        'label',
        'active_state',
        'sub_state',
        'unit_file_state',
        'main_pid',
        'active_enter_ts',
        'version',
        'is_custom',
        'can_manage',
        'captured_at',
        'pending_action',
        'pending_action_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_custom' => 'boolean',
            'can_manage' => 'boolean',
            'captured_at' => 'datetime',
            'pending_action_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo {
        return $this->belongsTo(Server::class);
    }
}
