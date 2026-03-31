<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InsightDigestQueue extends Model
{
    protected $table = 'insight_digest_queue';

    protected $fillable = [
        'insight_finding_id',
        'organization_id',
    ];

    public function finding(): BelongsTo
    {
        return $this->belongsTo(InsightFinding::class, 'insight_finding_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
