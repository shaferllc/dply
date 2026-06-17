<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property string $id
 */

class StatusPage extends Model
{
    /** @use HasFactory<StatusPageFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'organization_id',
        'user_id',
        'name',
        'slug',
        'description',
        'is_public',
    ];

    /** @return array<string, string> */
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

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<StatusPageMonitor, $this> */
    public function monitors(): HasMany {
        return $this->hasMany(StatusPageMonitor::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<Incident, $this> */
    public function incidents(): HasMany {
        return $this->hasMany(Incident::class)->orderByDesc('started_at');
    }

    public function openIncidents(): HasMany
    {
        return $this->incidents()->whereNull('resolved_at');
    }
}
