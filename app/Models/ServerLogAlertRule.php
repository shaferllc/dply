<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Logs\Console\EvaluateLogAlertsCommand;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 *                      A customer-defined dply Logs alert rule (paid tier): fire a notification when
 *                      shipped logs cross a threshold over a rolling window. Many rules per server.
 *                      Evaluated by {@see EvaluateLogAlertsCommand} on a schedule, gated on the org's
 *                      `alerting_enabled` entitlement. See docs/SERVER_LOGS_BILLING.md.
 * @property string $server_id
 * @property string $organization_id
 * @property string $name
 * @property string $type
 * @property ?string $level
 * @property ?string $source
 * @property ?string $search
 * @property int $threshold
 * @property int $window_minutes
 * @property int $cooldown_minutes
 * @property bool $enabled
 * @property ?Carbon $last_evaluated_at
 * @property ?Carbon $last_fired_at
 * @property ?int $last_count
 * @property-read ?Server $server
 * @property-read ?Organization $organization
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ServerLogAlertRule extends Model
{
    use HasUlids;

    /** A count-over-window rule (matching lines >= threshold). */
    public const TYPE_RATE = 'rate';

    /** A pattern rule (a line matching `search` appeared; threshold usually 1). */
    public const TYPE_PATTERN = 'pattern';

    protected $table = 'server_log_alert_rules';

    protected $fillable = [
        'server_id',
        'organization_id',
        'name',
        'type',
        'level',
        'source',
        'search',
        'threshold',
        'window_minutes',
        'cooldown_minutes',
        'enabled',
        'last_evaluated_at',
        'last_fired_at',
        'last_count',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'threshold' => 'integer',
            'window_minutes' => 'integer',
            'cooldown_minutes' => 'integer',
            'enabled' => 'boolean',
            'last_evaluated_at' => 'datetime',
            'last_fired_at' => 'datetime',
            'last_count' => 'integer',
        ];
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The ClickHouse facet filters this rule narrows on, in the shape
     * {@see \App\Modules\Logs\Services\LogExplorerQuery} expects. Empty values
     * are omitted so an unset facet doesn't constrain the count.
     *
     * @return array{level?:string,source?:string,search?:string}
     */
    public function facetFilters(): array
    {
        $filters = [];
        foreach (['level', 'source', 'search'] as $key) {
            $value = trim((string) ($this->{$key} ?? ''));
            if ($value !== '') {
                $filters[$key] = $value;
            }
        }

        return $filters;
    }

    /**
     * Whether the rule is still inside its post-fire cooldown — the evaluator
     * keeps measuring (so last_count stays fresh) but suppresses re-notifying.
     */
    public function isInCooldown(): bool
    {
        if ($this->last_fired_at === null || $this->cooldown_minutes <= 0) {
            return false;
        }

        return $this->last_fired_at->copy()->addMinutes($this->cooldown_minutes)->isFuture();
    }
}
