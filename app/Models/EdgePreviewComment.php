<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A review comment attached to a preview Edge site, anchored to a CSS
 * selector + viewport so the dashboard can replay it. Stored centrally
 * (not on GitHub) because we want non-engineer reviewers to leave
 * comments without a GitHub account — see C6 magic-link RBAC.
 */
class EdgePreviewComment extends Model
{
    use HasUlids;

    protected $table = 'edge_preview_comments';

    protected $fillable = [
        'organization_id',
        'site_id',
        'created_by_user_id',
        'author_label',
        'author_email',
        'selector',
        'viewport_width',
        'url_path',
        'body',
        'resolved_at',
        'resolved_by_user_id',
    ];

    protected $casts = [
        'viewport_width' => 'integer',
        'resolved_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    public function authorDisplayName(): string
    {
        if ($this->createdBy) {
            return (string) ($this->createdBy->name ?: $this->createdBy->email);
        }

        return (string) ($this->author_label ?: 'Guest');
    }
}
