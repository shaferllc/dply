<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\ServerCronJob;
use App\Models\ServerSchedulerHeartbeat;
use App\Models\Site;
use App\Modules\WordPress\Jobs\SwitchWordPressCronHandlerJob;

/**
 * The scheduler dply sets up for a site's detected stack, so enabling one is a
 * single click. A stack with no recipe gets "Set command…" instead.
 *
 * WordPress records the `generic` heartbeat kind: every dply-scheduler-tick
 * already on a box accepts it, so no wrapper upgrade is needed. Rows label a
 * scheduler by its recipe {@see $name}, never by the raw kind.
 */
final class SchedulerRecipe
{
    public const KEY_LARAVEL = 'laravel';

    public const KEY_WORDPRESS = 'wordpress';

    public const KEY_DRUPAL = 'drupal';

    public const KEY_CUSTOM = 'custom';

    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $label,
        public readonly string $summary,
        public readonly string $kind,
        public readonly string $command,
        public readonly string $cronExpression,
        public readonly string $user,
        public readonly string $overlapPolicy,
    ) {}

    public static function for(Site $site): ?self
    {
        if ($site->isWordPressDetected()) {
            // wp-cli runs as the SSH user everywhere else in dply, so the cron
            // line does too; skip-if-running stops a slow run piling up.
            return new self(
                key: self::KEY_WORDPRESS,
                name: 'WordPress',
                label: __('WordPress cron'),
                summary: 'wp cron event run --due-now',
                kind: ServerSchedulerHeartbeat::KIND_GENERIC,
                command: SwitchWordPressCronHandlerJob::command($site),
                cronExpression: '* * * * *',
                user: (string) $site->server?->ssh_user,
                overlapPolicy: ServerCronJob::OVERLAP_SKIP_IF_RUNNING,
            );
        }

        // Drupal's own cron is poor-man's cron on page views; drush runs it
        // properly. Enabling requires drush first — see EnableSchedulerJob.
        $scaffolded = strtolower((string) ($site->meta['scaffold']['framework'] ?? ''));
        if ($scaffolded === 'drupal' || $site->resolvedRuntimeFrameworkKey() === 'drupal') {
            return new self(
                key: self::KEY_DRUPAL,
                name: 'Drupal',
                label: __('Drupal cron'),
                summary: 'drush cron',
                kind: ServerSchedulerHeartbeat::KIND_GENERIC,
                command: 'cd '.$site->effectiveEnvDirectory().' && vendor/bin/drush cron',
                cronExpression: '*/15 * * * *',
                user: (string) $site->effectiveSystemUser($site->server),
                overlapPolicy: ServerCronJob::OVERLAP_SKIP_IF_RUNNING,
            );
        }

        // Statamic is a Laravel app and runs the same scheduler.
        if (in_array($site->resolvedRuntimeFrameworkKey(), ['laravel', 'statamic'], true)) {
            // No overlap lock: a schedule:run that outlives its minute would
            // drop the next minute's tasks. Laravel locks per task instead.
            return new self(
                key: self::KEY_LARAVEL,
                name: 'Laravel',
                label: __('Laravel scheduler'),
                summary: 'php artisan schedule:run',
                kind: ServerSchedulerHeartbeat::KIND_LARAVEL,
                command: 'cd '.$site->effectiveEnvDirectory().' && php artisan schedule:run',
                cronExpression: '* * * * *',
                user: (string) $site->effectiveSystemUser($site->server),
                overlapPolicy: ServerCronJob::OVERLAP_ALLOW,
            );
        }

        return null;
    }

    public static function custom(Site $site, string $command): self
    {
        return new self(
            key: self::KEY_CUSTOM,
            name: __('Custom'),
            label: __('Custom scheduler'),
            summary: trim($command),
            kind: ServerSchedulerHeartbeat::KIND_GENERIC,
            command: trim($command),
            cronExpression: '* * * * *',
            user: (string) $site->effectiveSystemUser($site->server),
            overlapPolicy: ServerCronJob::OVERLAP_ALLOW,
        );
    }
}
