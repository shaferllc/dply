<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per outbound webhook attempt — including the "would have been sent"
 * placeholder rows for events on servers without a webhook URL configured. Lets
 * users audit exactly what Dply emits even before they wire up an endpoint.
 */
class OutboundWebhookDelivery extends Model
{
    use HasUlids;

    /** No URL configured on the server; payload was built but no HTTP call made. */
    public const STATUS_WOULD_SEND = 'would_send';

    /** Queued for delivery, not yet attempted. */
    public const STATUS_PENDING = 'pending';

    /** HTTP attempt(s) made; got 2xx. */
    public const STATUS_SENT = 'sent';

    /** HTTP attempt(s) exhausted; non-2xx response or transport error. */
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'organization_id',
        'server_id',
        'event_key',
        'summary',
        'payload',
        'url',
        'signed',
        'signed_at',
        'status',
        'http_status',
        'attempt_count',
        'response_excerpt',
        'error_message',
        'first_attempt_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'signed' => 'boolean',
            'first_attempt_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
