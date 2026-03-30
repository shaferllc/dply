<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class StatusPage extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'organization_id',
        'user_id',
        'name',
        'slug',
        'description',
        'is_public',
    ];

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (StatusPage $page): void {
            if (empty($page->slug)) {
                $page->slug = Str::slug($page->name) ?: 'status';
            }

            $base = $page->slug;
            $n = 0;
            while (static::query()->where('slug', $page->slug)->exists()) {
                $n++;
                $page->slug = $base.'-'.$n;
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function monitors(): HasMany
    {
        return $this->hasMany(StatusPageMonitor::class)->orderBy('sort_order')->orderBy('id');
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class)->orderByDesc('started_at');
    }

    public function openIncidents(): HasMany
    {
        return $this->incidents()->whereNull('resolved_at');
    }
}
