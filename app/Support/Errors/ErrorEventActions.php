<?php

declare(strict_types=1);

namespace App\Support\Errors;

use App\Models\ErrorEvent;
use App\Modules\Remediations\Jobs\ApplyRemediationJob;
use App\Modules\Remediations\Services\RemediationCatalog;
use Illuminate\Database\Eloquent\Builder;

/**
 * What you can *do* to an error event: dismiss it, re-run the operation behind
 * it, or apply its known fix.
 *
 * Extracted from {@see \App\Livewire\Concerns\SurfacesErrorStream} when the
 * HTTP API grew the same three verbs for the CLI. The trait keeps owning the
 * Livewire-facing bits (URL state, pagination, toasts); the decisions — is this
 * retryable, which remediation action, is it tied to a server — live here so a
 * browser dismiss and a `dply errors dismiss` cannot drift apart.
 *
 * Callers pass a scoped query, so "dismiss this id" can only ever touch a row
 * the caller already proved the actor may see.
 */
class ErrorEventActions
{
    public function __construct(
        private ErrorRetryRegistry $registry,
        private RemediationCatalog $catalog,
    ) {}

    /**
     * @param  Builder<ErrorEvent>  $scope
     * @return int  rows dismissed (0 when the id is out of scope or already dismissed)
     */
    public function dismiss(Builder $scope, string $id, ?string $userId): int
    {
        return $scope->clone()->whereKey($id)->whereNull('dismissed_at')->update([
            'dismissed_at' => now(),
            'dismissed_by' => $userId,
        ]);
    }

    /**
     * @param  Builder<ErrorEvent>  $scope
     * @return int  rows dismissed
     */
    public function dismissAll(Builder $scope, ?string $userId): int
    {
        return $scope->clone()->whereNull('dismissed_at')->update([
            'dismissed_at' => now(),
            'dismissed_by' => $userId,
        ]);
    }

    /**
     * @param  Builder<ErrorEvent>  $scope
     * @return int  rows restored
     */
    public function restore(Builder $scope, string $id): int
    {
        return $scope->clone()->whereKey($id)->update(['dismissed_at' => null, 'dismissed_by' => null]);
    }

    /** False when the category isn't registered as retryable, or the origin row is gone. */
    public function retry(ErrorEvent $event, ?string $userId): bool
    {
        return $this->registry->retry($event, $userId);
    }

    /**
     * Queue the known fix for an error. Defaults to the recommended action.
     *
     * @return 'applied'|'no_fix'|'stale_action'|'manual'|'no_server'
     */
    public function applyRemediation(ErrorEvent $event, ?string $actionKey, ?string $userId): string
    {
        $remediation = $event->remediation();
        if ($remediation === null) {
            return 'no_fix';
        }

        $actions = $remediation['actions'] ?? [];
        $actionKey ??= collect($actions)->firstWhere('recommended', true)['key'] ?? ($actions[0]['key'] ?? null);
        $action = $actionKey === null ? null : $this->catalog->action((string) $event->remediation_code, $actionKey);

        if ($action === null) {
            return 'stale_action';
        }

        // Some catalog actions are a link to a settings page, not a script —
        // {@see ApplyRemediationJob} only runs `script`/`handler` ones. The web
        // view renders those as an anchor; anything else must be told to.
        if (($action['script'] ?? null) === null && ($action['handler'] ?? null) === null) {
            return 'manual';
        }

        if ($event->server_id === null) {
            return 'no_server';
        }

        ApplyRemediationJob::dispatch(
            (string) $event->server_id,
            $event->site_id ? (string) $event->site_id : null,
            (string) $event->remediation_code,
            $actionKey,
            $userId ?: null,
            (string) $event->id,
        );

        return 'applied';
    }

    /** Which of the three verbs this event actually supports — the CLI/UI action list. */
    public function isRetryable(ErrorEvent $event): bool
    {
        return $this->registry->isRetryable((string) $event->category);
    }
}
