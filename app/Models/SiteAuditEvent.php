<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\RemoteCli\RemoteCli;
use App\Services\RemoteCli\RiskLevel;
use App\Services\Snapshots\SnapshotService;
use Database\Factories\SiteAuditEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only audit row for a mutating action against a Site.
 *
 * Written by the {@see RemoteCli} services (PR 2),
 * the {@see SnapshotService} (PR 10), the
 * scaffold pipeline (PR 5/6), and the WordPress hardening surface (PR 10).
 * Reads don't audit by default; only mutating-recoverable and destructive
 * actions plus settled (success or failure) lifecycle events.
 *
 * @property int $id
 * @property string $site_id
 * @property string|null $user_id
 * @property string $action
 * @property RiskLevel $risk
 * @property string $transport 'web' | 'cli' | 'system'
 * @property string $summary
 * @property array<string, mixed>|null $payload
 * @property string $result_status 'success' | 'failure'
 * @property Carbon $created_at
 */
class SiteAuditEvent extends Model
{
    use HasFactory;

    protected $table = 'site_audit_events';

    /** No updated_at — audit rows are append-only. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'site_id',
        'user_id',
        'action',
        'risk',
        'transport',
        'summary',
        'payload',
        'result_status',
    ];

    public const TRANSPORT_WEB = 'web';

    public const TRANSPORT_CLI = 'cli';

    public const TRANSPORT_SYSTEM = 'system';

    public const RESULT_SUCCESS = 'success';

    public const RESULT_FAILURE = 'failure';

    protected function casts(): array
    {
        return [
            'risk' => RiskLevel::class,
            'payload' => 'array',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function newFactory(): SiteAuditEventFactory
    {
        return SiteAuditEventFactory::new();
    }
}
