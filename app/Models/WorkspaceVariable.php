<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $env_key
 * @property ?string $env_value
 * @property bool $is_secret
 * @property ?string $workspace_id
 * @property-read ?Workspace $workspace
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class WorkspaceVariable extends Model
{
    use HasUlids;

    protected $fillable = [
        'workspace_id',
        'env_key',
        'env_value',
        'is_secret',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'env_value' => 'encrypted',
            'is_secret' => 'boolean',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
