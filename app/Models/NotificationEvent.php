<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class NotificationEvent extends Model
{
    use HasUlids;

    protected $fillable = [
        'event_key',
        'subject_type',
        'subject_id',
        'resource_type',
        'resource_id',
        'organization_id',
        'team_id',
        'actor_id',
        'title',
        'body',
        'url',
        'severity',
        'category',
        'supports_in_app',
        'supports_email',
        'supports_webhook',
        'metadata',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'supports_in_app' => 'boolean',
            'supports_email' => 'boolean',
            'supports_webhook' => 'boolean',
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function inboxItems(): HasMany
    {
        return $this->hasMany(NotificationInboxItem::class);
    }

    public function scopeForResource($query, string $resourceType, string $resourceId)
    {
        return $query
            ->where('resource_type', $resourceType)
            ->where('resource_id', $resourceId);
    }
}
