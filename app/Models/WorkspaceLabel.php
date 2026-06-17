<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property string $id
 */

class WorkspaceLabel extends Model
{
    use HasUlids;

    protected $fillable = [
        'organization_id',
        'name',
        'slug',
        'color',
    ];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsToMany<Workspace, $this> */
    public function workspaces(): BelongsToMany {
        return $this->belongsToMany(Workspace::class, 'workspace_label_assignments')
            ->withTimestamps();
    }
}
