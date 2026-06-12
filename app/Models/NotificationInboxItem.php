<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationInboxItem extends Model
{
    use HasUlids;

    protected $fillable = [
        'notification_event_id',
        'user_id',
        'resource_type',
        'resource_id',
        'title',
        'body',
        'url',
        'metadata',
        'read_at',
        'saved_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'read_at' => 'datetime',
            'saved_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(NotificationEvent::class, 'notification_event_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    /** Starred "save to remember" items — the user's curated keep-list. */
    public function scopeSaved($query)
    {
        return $query->whereNotNull('saved_at');
    }

    public function isUnread(): bool
    {
        return $this->read_at === null;
    }

    public function isSaved(): bool
    {
        return $this->saved_at !== null;
    }

    /**
     * The URL a primary action button should point at — an explicit cta_url in
     * metadata, else the item's own deep link. Null when there's nothing to act on.
     */
    public function ctaUrl(): ?string
    {
        $meta = is_array($this->metadata) ? $this->metadata : [];
        $cta = trim((string) ($meta['cta_url'] ?? ''));

        return $cta !== '' ? $cta : ($this->url ?: null);
    }

    /**
     * The label for that action button. Honors an explicit cta_label in metadata,
     * otherwise infers a sensible verb so the user can see there's something to
     * click ("Download" for download-ready items, "Open" for everything else).
     */
    public function ctaLabel(): ?string
    {
        if ($this->ctaUrl() === null) {
            return null;
        }

        $meta = is_array($this->metadata) ? $this->metadata : [];
        $explicit = trim((string) ($meta['cta_label'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        $haystack = strtolower((string) $this->title.' '.(string) $this->body);

        return str_contains($haystack, 'download') ? __('Download') : __('Open');
    }
}
