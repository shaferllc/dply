<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookDeliveryLog extends Model
{
    use HasUlids;

    public const OUTCOME_ACCEPTED = 'accepted';

    public const OUTCOME_REJECTED = 'rejected';

    public const OUTCOME_ERROR = 'error';

    protected $fillable = [
        'site_id',
        'request_ip',
        'http_status',
        'outcome',
        'detail',
        'provider_event',
        'provider_delivery_id',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
