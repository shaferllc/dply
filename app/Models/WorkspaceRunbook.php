<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $body
 * @property string $sort_order
 * @property string $title
 * @property string $url
 * @property ?string $workspace_id
 * @property-read ?Workspace $workspace
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class WorkspaceRunbook extends Model
{
    use HasUlids;

    protected $fillable = [
        'workspace_id',
        'title',
        'url',
        'body',
        'sort_order',
    ];

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
