<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Support\Sites\SiteManagedErrorPageSupport;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesSiteServerErrors
{
    /** True when this site is passing raw 5xx through instead of the branded page. */
    public function serverErrorsExposed(): bool
    {
        return SiteManagedErrorPageSupport::serverErrorsExposed($this->site);
    }

    /**
     * Operator debugging aid: stop intercepting 5xx with the branded
     * "temporarily unavailable" page so the real error shows — the framework
     * debug page on an app 500, or the webserver's own 502/503/504 when the
     * upstream is down. Off by default; re-applies the managed webserver config
     * so the change takes effect on the box.
     */
    public function exposeServerErrors(): void
    {
        $this->authorize('update', $this->site);
        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $meta[SiteManagedErrorPageSupport::META_EXPOSE_FLAG] = true;
        $this->site->forceFill(['meta' => $meta])->save();
        $this->finalizeRoutingMutation(
            __('Showing raw server errors for this site — visitors see the real 5xx page until you turn this back off.'),
            __('Exposing raw server errors …'),
        );
    }

    /** Restore the branded managed error page (hide raw 5xx errors again). */
    public function hideServerErrors(): void
    {
        $this->authorize('update', $this->site);
        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        unset($meta[SiteManagedErrorPageSupport::META_EXPOSE_FLAG]);
        $this->site->forceFill(['meta' => $meta])->save();
        $this->finalizeRoutingMutation(
            __('Branded error page restored — raw server errors are hidden again.'),
            __('Restoring branded error page …'),
        );
    }
}
