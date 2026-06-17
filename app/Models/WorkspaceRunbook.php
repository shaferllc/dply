<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
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
    public function workspace(): BelongsTo {
        return $this->belongsTo(Workspace::class);
    }
}
