<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookDeliveryLog extends Model
{
    public const OUTCOME_ACCEPTED = 'accepted';

    public const OUTCOME_REJECTED = 'rejected';

    public const OUTCOME_ERROR = 'error';

    protected $fillable = [
        'site_id',
        'request_ip',
        'http_status',
        'outcome',
        'detail',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
