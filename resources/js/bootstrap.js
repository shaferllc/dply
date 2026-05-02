import axios from 'axios';

window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

/**
 * Laravel Echo / Reverb is initialized in resources/views/partials/reverb-echo-module.blade.php
 * (import map + ES module from esm.sh) so connection options never come from stale public/build chunks.
 */

/**
 * Subscribe to org-scoped server updates (Reverb) and refresh Livewire when provisioning changes.
 */
function bindDplyOrganizationServerChannel() {
    if (!window.Echo || typeof window.Livewire === 'undefined') {
        return;
    }

    const el = document.getElementById('dply-broadcast-context');
    const orgId = el?.dataset?.organizationId?.trim() ?? '';

    if (!orgId) {
        if (window.__dplyOrgEchoSub) {
            window.Echo.leave(`organization.${window.__dplyOrgEchoSub}`);
            window.__dplyOrgEchoSub = null;
        }
        return;
    }

    if (window.__dplyOrgEchoSub === orgId) {
        return;
    }

    if (window.__dplyOrgEchoSub) {
        window.Echo.leave(`organization.${window.__dplyOrgEchoSub}`);
    }

    window.__dplyOrgEchoSub = orgId;

    window.Echo.private(`organization.${orgId}`).listen('.server.state.updated', (payload) => {
        window.Livewire.dispatch('server-state-updated', {
            organizationId: payload.organization_id,
            action: payload.action,
            serverId: payload.server_id,
            server: payload.server,
        });
    });
}

/**
 * Subscribe to server-scoped log snapshots (Reverb) so extra tabs stay in sync without duplicate SSH tails.
 */
function bindDplyServerLogChannel() {
    if (!window.Echo || typeof window.Livewire === 'undefined') {
        return;
    }

    const el = document.getElementById('dply-server-log-broadcast-context');
    const serverId = el?.dataset?.serverId?.trim() ?? '';
    const subscribe = el?.dataset?.subscribe === '1';

    if (!subscribe || !serverId) {
        if (window.__dplyServerLogEchoSub) {
            window.Echo.leave('server.' + window.__dplyServerLogEchoSub);
            window.__dplyServerLogEchoSub = null;
        }
        return;
    }

    if (window.__dplyServerLogEchoSub === serverId) {
        return;
    }

    if (window.__dplyServerLogEchoSub) {
        window.Echo.leave('server.' + window.__dplyServerLogEchoSub);
    }

    window.__dplyServerLogEchoSub = serverId;

    window.Echo.private('server.' + serverId).listen('.server.workspace.log.snapshot', (payload) => {
        window.Livewire.dispatch('server-workspace-log-snapshot', payload);
    });
}

/**
 * Cron “Run now”: queued SSH + Reverb chunks on private server channel.
 */
function bindDplyServerCronRunChannel() {
    if (!window.Echo || typeof window.Livewire === 'undefined') {
        return;
    }

    const el = document.getElementById('dply-server-cron-run-context');
    const serverId = el?.dataset?.serverId?.trim() ?? '';
    const subscribe = el?.dataset?.subscribe === '1';

    if (!subscribe || !serverId) {
        if (window.__dplyCronRunEchoSub) {
            window.Echo.leave('server.' + window.__dplyCronRunEchoSub);
            window.__dplyCronRunEchoSub = null;
        }

        return;
    }

    if (window.__dplyCronRunEchoSub === serverId) {
        return;
    }

    if (window.__dplyCronRunEchoSub) {
        window.Echo.leave('server.' + window.__dplyCronRunEchoSub);
    }

    window.__dplyCronRunEchoSub = serverId;

    const ch = window.Echo.private('server.' + serverId);

    /**
     * Queue workers often broadcast before the Livewire response runs $this->js(), so
     * __dplyCronRunActiveId can be unset — adopt run_id from the first event on this channel.
     */
    function cronRunAccepts(payloadRunId) {
        if (!payloadRunId) {
            return false;
        }
        if (!window.__dplyCronRunActiveId) {
            window.__dplyCronRunActiveId = payloadRunId;

            return true;
        }

        return payloadRunId === window.__dplyCronRunActiveId;
    }

    ch.listen('.server.cron.run.meta', (payload) => {
        if (!payload?.meta_html || !cronRunAccepts(payload.run_id)) {
            return;
        }
        window.Livewire.dispatch('cron-run-meta', {
            runId: payload.run_id,
            metaHtml: payload.meta_html,
        });
    });

    ch.listen('.server.cron.run.chunk', (payload) => {
        if (!payload?.chunk || !cronRunAccepts(payload.run_id)) {
            return;
        }
        window.Livewire.dispatch('cron-run-chunk', {
            runId: payload.run_id,
            chunk: payload.chunk,
        });
    });

    /**
     * Chunks/meta update Livewire state above (live). Completion clears the run and flashes.
     * wire:poll still syncs cache when Reverb is off or events are missed.
     */
    ch.listen('.server.cron.run.completed', (payload) => {
        if (!payload?.run_id || !cronRunAccepts(payload.run_id)) {
            return;
        }
        window.Livewire.dispatch('cron-run-finished', {
            runId: payload.run_id,
            success: !!payload.success,
            flashSuccess: payload.flash_success ?? null,
            error: payload.error ?? null,
        });
        window.__dplyCronRunActiveId = null;
    });
}

function bindDplySiteProvisioningChannel() {
    if (!window.Echo || typeof window.Livewire === 'undefined') {
        return;
    }

    const el = document.getElementById('dply-site-provisioning-context');
    const siteId = el?.dataset?.siteId?.trim() ?? '';
    const subscribe = el?.dataset?.subscribe === '1';

    if (!subscribe || !siteId) {
        if (window.__dplySiteProvisioningEchoSite) {
            window.Echo.leave('site.' + window.__dplySiteProvisioningEchoSite);
            window.__dplySiteProvisioningEchoSite = null;
        }

        return;
    }

    if (window.__dplySiteProvisioningEchoSite === siteId) {
        return;
    }

    if (window.__dplySiteProvisioningEchoSite) {
        window.Echo.leave('site.' + window.__dplySiteProvisioningEchoSite);
    }

    window.__dplySiteProvisioningEchoSite = siteId;

    window.Echo.private('site.' + siteId).listen('.site.provisioning.updated', (payload) => {
        if (payload?.site_id !== siteId) {
            return;
        }

        window.Livewire.dispatch('site-provisioning-updated', {
            siteId: payload.site_id,
            status: payload.status,
            provisioningState: payload.provisioning_state,
        });
    });
}

document.addEventListener('livewire:init', () => {
    bindDplyOrganizationServerChannel();
    bindDplyServerLogChannel();
    bindDplyServerCronRunChannel();
    bindDplySiteProvisioningChannel();
});

document.addEventListener('livewire:navigated', () => {
    bindDplyOrganizationServerChannel();
    bindDplyServerLogChannel();
    bindDplyServerCronRunChannel();
    bindDplySiteProvisioningChannel();
});

// Echo loads in a deferred <head> module (before this bundle); if Livewire beat it, bind when ready.
document.addEventListener('dply:echo-ready', () => {
    bindDplyOrganizationServerChannel();
    bindDplyServerLogChannel();
    bindDplyServerCronRunChannel();
    bindDplySiteProvisioningChannel();
});
