<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Incident extends Model
{
    use HasFactory, HasUlids;

    public const IMPACT_NONE = 'none';

    public const IMPACT_MINOR = 'minor';

    public const IMPACT_MAJOR = 'major';

    public const IMPACT_CRITICAL = 'critical';

    public const STATE_INVESTIGATING = 'investigating';

    public const STATE_IDENTIFIED = 'identified';

    public const STATE_MONITORING = 'monitoring';

    public const STATE_RESOLVED = 'resolved';

    protected $fillable = [
        'status_page_id',
        'user_id',
        'title',
        'impact',
        'state',
        'started_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function statusPage(): BelongsTo
    {
        return $this->belongsTo(StatusPage::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function incidentUpdates(): HasMany
    {
        return $this->hasMany(IncidentUpdate::class)->orderBy('created_at');
    }

    public function isOpen(): bool
    {
        return $this->resolved_at === null;
    }
}
