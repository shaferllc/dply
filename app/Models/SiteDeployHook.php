<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $site_id
 * @property string $pipeline_id
 * @property int $sort_order
 * @property string $phase
 * @property string $hook_kind
 * @property string $anchor
 * @property ?string $anchor_step_id
 * @property ?string $label
 * @property ?string $notification_event
 * @property ?string $notification_channel_id
 * @property ?string $webhook_url
 * @property ?string $script
 * @property ?int $timeout_seconds
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read Site $site
 * @property-read SiteDeployPipeline $pipeline
 * @property-read ?SiteDeployStep $anchorStep
 * @property-read ?NotificationChannel $notificationChannel
 */
class SiteDeployHook extends Model
{
    use HasUlids;

    public const PHASE_BEFORE_CLONE = 'before_clone';

    public const PHASE_AFTER_CLONE = 'after_clone';

    public const PHASE_AFTER_ACTIVATE = 'after_activate';

    public const KIND_SHELL = 'shell';

    public const KIND_WEBHOOK = 'webhook';

    public const KIND_NOTIFICATION = 'notification';

    public const ANCHOR_BEFORE_CLONE = 'before_clone';

    public const ANCHOR_AFTER_CLONE = 'after_clone';

    public const ANCHOR_AFTER_STEP = 'after_step';

    public const ANCHOR_BEFORE_ACTIVATE = 'before_activate';

    public const ANCHOR_AFTER_ACTIVATE = 'after_activate';

    public const NOTIFICATION_EVENT_DEPLOY_STARTED = 'site.deployment_started';

    public const NOTIFICATION_EVENT_DEPLOY_OUTCOME = 'site.deployments';

    protected $fillable = [
        'site_id',
        'pipeline_id',
        'sort_order',
        'phase',
        'hook_kind',
        'anchor',
        'anchor_step_id',
        'label',
        'notification_event',
        'notification_channel_id',
        'webhook_url',
        'script',
        'timeout_seconds',
    ];

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<SiteDeployPipeline, $this> */
    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(SiteDeployPipeline::class, 'pipeline_id');
    }

    /** @return BelongsTo<SiteDeployStep, $this> */
    public function anchorStep(): BelongsTo
    {
        return $this->belongsTo(SiteDeployStep::class, 'anchor_step_id');
    }

    /** @return BelongsTo<NotificationChannel, $this> */
    public function notificationChannel(): BelongsTo
    {
        return $this->belongsTo(NotificationChannel::class);
    }

    /** @return list<string> */
    public static function anchors(): array
    {
        return [
            self::ANCHOR_BEFORE_CLONE,
            self::ANCHOR_AFTER_CLONE,
            self::ANCHOR_AFTER_STEP,
            self::ANCHOR_BEFORE_ACTIVATE,
            self::ANCHOR_AFTER_ACTIVATE,
        ];
    }

    /** @return list<string> */
    public static function kinds(): array
    {
        return [self::KIND_SHELL, self::KIND_WEBHOOK, self::KIND_NOTIFICATION];
    }

    public function pillLabel(): string
    {
        if (trim((string) $this->label) !== '') {
            return (string) $this->label;
        }

        return match ($this->hook_kind) {
            self::KIND_WEBHOOK => __('Webhook'),
            self::KIND_NOTIFICATION => ($this->notificationChannel !== null ? $this->notificationChannel->label : null) ?? __('Notify'),
            default => match ($this->anchor) {
                self::ANCHOR_BEFORE_CLONE => __('Shell · before clone'),
                self::ANCHOR_BEFORE_ACTIVATE => __('Shell · before activate'),
                self::ANCHOR_AFTER_ACTIVATE => __('Shell · after activate'),
                self::ANCHOR_AFTER_STEP => __('Shell · after step'),
                default => __('Shell · after clone'),
            },
        };
    }

    public function pillIcon(): string
    {
        return match ($this->hook_kind) {
            self::KIND_WEBHOOK => 'heroicon-o-globe-alt',
            self::KIND_NOTIFICATION => 'heroicon-o-bell-alert',
            default => 'heroicon-o-bolt',
        };
    }

    public function pillToneClass(): string
    {
        return match ($this->hook_kind) {
            self::KIND_WEBHOOK => 'border-violet-200 bg-violet-50 text-violet-900',
            self::KIND_NOTIFICATION => 'border-amber-200 bg-amber-50 text-amber-950',
            default => 'border-brand-sage/30 bg-brand-sage/10 text-brand-forest',
        };
    }

    /** @return array<string, string> */
    public static function anchorLabels(): array
    {
        return [
            self::ANCHOR_BEFORE_CLONE => __('Before clone'),
            self::ANCHOR_AFTER_CLONE => __('After clone'),
            self::ANCHOR_AFTER_STEP => __('After a build step'),
            self::ANCHOR_BEFORE_ACTIVATE => __('Before activate (swap)'),
            self::ANCHOR_AFTER_ACTIVATE => __('After activate'),
        ];
    }

    /** @return array<string, string> */
    public static function kindLabels(): array
    {
        return [
            self::KIND_SHELL => __('Run shell on server'),
            self::KIND_WEBHOOK => __('HTTP webhook'),
            self::KIND_NOTIFICATION => __('Notification channel'),
        ];
    }
}
