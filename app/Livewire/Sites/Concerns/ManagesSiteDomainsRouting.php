<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Jobs\ApplySiteWebserverConfigJob;
use App\Jobs\AttachCloudDomainJob;
use App\Jobs\DetachCloudDomainJob;
use App\Jobs\ExecuteSiteCertificateJob;
use App\Models\Site;
use App\Models\SiteAuditEvent;
use App\Models\SiteCertificate;
use App\Models\SiteDomain;
use App\Services\Certificates\CertificateRequestService;
use App\Services\RemoteCli\RiskLevel;
use App\Services\RemoteCli\SiteAuditWriter;
use App\Services\Sites\PrimaryHostnameRenamePlanner;
use App\Support\HostnameValidator;
use Illuminate\Validation\Rule;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesSiteDomainsRouting
{
    public string $new_domain_hostname = '';

    /** Optional intent comment captured at add-time and rendered on the row. */
    public string $new_domain_comment = '';

    /** Multi-line bulk paste — one hostname per line. */
    public string $bulk_domain_input = '';

    /** When non-null, the domains list shows an inline edit form for this row. */
    public ?string $editing_domain_id = null;

    /**
     * Cascade preview for a primary-hostname rename, set by saveEditedDomain()
     * when the edited row is the primary AND the hostname actually changed AND
     * the rename has non-trivial cascades (existing cert, container backend, …).
     * Consumed by the confirmation modal in routing.blade.php. Null when no
     * rename is pending. Shape matches {@see PrimaryHostnameRenamePlanner::plan()}.
     *
     * @var array{old: string, new: string, auto: list<array{key: string, label: string}>, optIn: list<array{key: string, label: string, detail?: array<string, mixed>}>, manual: list<string>}|null
     */
    public ?array $rename_plan = null;

    /** Opt-in: re-issue SSL cert covering the new hostname during rename confirmation. */
    public bool $rename_reissue_cert = false;

    /** Opt-in: detach old + attach new on the site's container backend during rename confirmation. */
    public bool $rename_cycle_backend = false;

    public string $editing_domain_hostname = '';

    public string $editing_domain_comment = '';

    /**
     * Dispatches the webserver-config apply for the current site (when the host
     * runtime supports it) and shows a toast.
     *
     * `$bannerLabel` is the user-perceived action shown in the page-top
     * console-action banner — "Removing credential", "Saving site settings",
     * etc. NULL falls back to the kind's default copy ("Applying webserver
     * config to :host …"). Setting it lets a single shared apply job carry
     * different banner titles depending on which UI path triggered it.
     */
    protected function finalizeRoutingMutation(string $successMessage, ?string $bannerLabel = null): void
    {
        if (! $this->shouldAutoReapplyManagedWebserverConfig()) {
            $this->toastSuccess($successMessage);

            return;
        }

        // Pre-seed a queued console_actions row so the banner appears immediately
        // (before the worker picks the job up), and so we can stamp the per-action
        // label. The job's beginConsoleAction() reuses this row instead of
        // creating a new one.
        $run = $this->seedQueuedConsoleAction('webserver_config', $bannerLabel);

        // Queued: errors surface via the apply banner (status=failed) on the next
        // poll, not as inline toasts. Inline-running this used to time out HTTP requests
        // because SSH/nginx work was happening synchronously on the web worker.
        ApplySiteWebserverConfigJob::dispatch(
            $this->site->id,
            (string) (auth()->id() ?? ''),
            (string) $run->id,
        );

        $this->dispatch('dply-console-action-focus');
        $this->watchConsoleAction(
            $run,
            $successMessage,
            __('Webserver config could not be applied on the server.'),
        );
        $this->toastConsoleActionQueued();
    }

    public function addDomain(): void
    {
        $this->authorize('update', $this->site);
        $this->validate([
            'new_domain_hostname' => [
                'required',
                'string',
                'max:255',
                'unique:site_domains,hostname',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || ! HostnameValidator::isValid($value)) {
                        $fail('Enter a valid domain name like app.example.com.');
                    }
                },
            ],
            'new_domain_comment' => ['nullable', 'string', 'max:2000'],
        ]);
        $newDomain = SiteDomain::query()->create([
            'site_id' => $this->site->id,
            'hostname' => strtolower(trim($this->new_domain_hostname)),
            'is_primary' => false,
            'www_redirect' => false,
            'comment' => trim($this->new_domain_comment) ?: null,
        ]);

        $org = $this->site->server?->organization;
        if ($org) {
            audit_log($org, auth()->user(), 'site.domain.added', $this->site, null, [
                'domain_id' => (string) $newDomain->id,
                'hostname' => $newDomain->hostname,
            ]);
        }

        $this->new_domain_hostname = '';
        $this->new_domain_comment = '';
        $this->finalizeRoutingMutation('Domain added.');
    }

    public function editDomain(int|string $domainId): void
    {
        $this->authorize('update', $this->site);
        $domain = SiteDomain::query()->where('site_id', $this->site->id)->findOrFail($domainId);
        $this->editing_domain_id = (string) $domain->id;
        $this->editing_domain_hostname = (string) $domain->hostname;
        $this->editing_domain_comment = (string) ($domain->comment ?? '');
    }

    public function cancelEditDomain(): void
    {
        $this->editing_domain_id = null;
        $this->editing_domain_hostname = '';
        $this->editing_domain_comment = '';
    }

    public function saveEditedDomain(): void
    {
        $this->authorize('update', $this->site);
        if ($this->editing_domain_id === null) {
            return;
        }
        $domain = SiteDomain::query()->where('site_id', $this->site->id)->findOrFail($this->editing_domain_id);
        $this->validate([
            'editing_domain_hostname' => [
                'required',
                'string',
                'max:255',
                Rule::unique('site_domains', 'hostname')->ignore($domain->id),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || ! HostnameValidator::isValid($value)) {
                        $fail('Enter a valid domain name like app.example.com.');
                    }
                },
            ],
            'editing_domain_comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $oldHostname = strtolower(trim((string) $domain->hostname));
        $newHostname = strtolower(trim($this->editing_domain_hostname));
        $commentChanged = trim($this->editing_domain_comment) !== (string) ($domain->comment ?? '');
        $isPrimary = (bool) $domain->is_primary;

        // Comment-only edits or non-primary domain edits skip the cascade entirely
        // — same code path as before. The cascade only triggers when the row IS
        // the primary AND its hostname actually changed AND the rename is non-trivial.
        if (! $isPrimary || $oldHostname === $newHostname) {
            $domain->forceFill([
                'hostname' => $newHostname,
                'comment' => trim($this->editing_domain_comment) ?: null,
            ])->save();

            $org = $this->site->server?->organization;
            if ($org) {
                audit_log($org, auth()->user(), 'site.domain.updated', $this->site, [
                    'hostname' => $oldHostname,
                ], [
                    'domain_id' => (string) $domain->id,
                    'hostname' => $newHostname,
                ]);
            }

            $this->cancelEditDomain();
            $this->finalizeRoutingMutation('Domain updated.');

            return;
        }

        // Persist the comment edit immediately — it's independent of the hostname
        // cascade and the operator may have edited both fields together.
        if ($commentChanged) {
            $domain->forceFill(['comment' => trim($this->editing_domain_comment) ?: null])->save();
        }

        $planner = app(PrimaryHostnameRenamePlanner::class);
        $plan = $planner->plan($this->site, $newHostname);

        if ($planner->isTrivial($plan)) {
            $domain->forceFill(['hostname' => $newHostname])->save();
            $this->recordRenameAudit($oldHostname, $newHostname, [], false);
            $this->cancelEditDomain();
            $this->finalizeRoutingMutation('Primary hostname renamed.');

            return;
        }

        $this->rename_plan = $plan;
        $this->rename_reissue_cert = false;
        $this->rename_cycle_backend = false;
        $this->dispatch('open-modal', 'primary-hostname-rename-modal');
    }

    /**
     * Commit the primary-hostname rename selected in the confirmation modal,
     * applying the opt-in cascades the operator checked. Mutates only when
     * `$rename_plan` is set (defensive — the modal can't fire otherwise).
     */
    public function confirmPrimaryHostnameRename(): void
    {
        $this->authorize('update', $this->site);

        if ($this->rename_plan === null) {
            return;
        }

        $primaryDomain = $this->site->primaryDomain();
        if ($primaryDomain === null) {
            $this->rename_plan = null;

            return;
        }

        $old = strtolower(trim((string) $primaryDomain->hostname));
        $new = strtolower(trim((string) $this->rename_plan['new']));

        // Re-plan defensively in case the site changed under us (modal could
        // have been open while another tab issued a cert, etc.). Use the fresh
        // plan to decide which opt-ins are still applicable.
        $planner = app(PrimaryHostnameRenamePlanner::class);
        $freshPlan = $planner->plan($this->site, $new);
        $optInKeys = array_map(fn (array $row) => $row['key'], $freshPlan['optIn']);

        $reissueCert = $this->rename_reissue_cert && in_array('reissue_cert', $optInKeys, true);
        $cycleBackend = $this->rename_cycle_backend && in_array('cycle_backend', $optInKeys, true);
        $rewriteDnsZone = collect($freshPlan['auto'])->contains(fn (array $row) => $row['key'] === 'dns_zone');

        $primaryDomain->forceFill(['hostname' => $new])->save();

        if ($rewriteDnsZone) {
            $this->site->update([
                'dns_zone' => Site::apexGuessForHostname($new),
            ]);
        }

        $cascadeKeys = [];
        if ($reissueCert) {
            $cascadeKeys[] = 'reissue_cert';
            $this->dispatchCertReissue($freshPlan);
        }
        if ($cycleBackend) {
            $cascadeKeys[] = 'cycle_backend';
            DetachCloudDomainJob::dispatch($this->site->id, $old);
            AttachCloudDomainJob::dispatch($this->site->id, $new);
        }

        $this->recordRenameAudit($old, $new, $cascadeKeys, $rewriteDnsZone);

        $this->rename_plan = null;
        $this->rename_reissue_cert = false;
        $this->rename_cycle_backend = false;
        $this->dispatch('close-modal', 'primary-hostname-rename-modal');
        $this->cancelEditDomain();
        $this->finalizeRoutingMutation('Primary hostname renamed.');
    }

    /**
     * Discard the pending rename — leaves the row's edit form open with the
     * unsaved hostname so the operator can keep editing or revert manually.
     */
    public function cancelPrimaryHostnameRename(): void
    {
        $this->rename_plan = null;
        $this->rename_reissue_cert = false;
        $this->rename_cycle_backend = false;
        $this->dispatch('close-modal', 'primary-hostname-rename-modal');
    }

    /**
     * Clone the customer-scope certs that covered the old hostname and queue
     * issuance against the now-current `sslIssuanceHostnames()`. Mirrors the
     * quick-issue flow at {@see SiteSettings::saveQuickDomainSslModal()}.
     *
     * @param  array{optIn: list<array{key: string, label: string, detail?: array<string, mixed>}>}  $plan
     */
    private function dispatchCertReissue(array $plan): void
    {
        $row = collect($plan['optIn'] ?? [])->firstWhere('key', 'reissue_cert');
        $certIds = is_array($row) ? ($row['detail']['cert_ids'] ?? []) : [];
        if (! is_array($certIds) || $certIds === []) {
            return;
        }

        $certificateRequestService = app(CertificateRequestService::class);
        $sourceCerts = SiteCertificate::query()
            ->where('site_id', $this->site->id)
            ->whereIn('id', $certIds)
            ->get();
        $newHostnames = $this->site->sslIssuanceHostnames();

        foreach ($sourceCerts as $source) {
            $certificate = $certificateRequestService->create([
                'site_id' => $this->site->id,
                'scope_type' => $source->scope_type ?? SiteCertificate::SCOPE_CUSTOMER,
                'provider_type' => $source->provider_type ?? SiteCertificate::PROVIDER_LETSENCRYPT,
                'challenge_type' => $source->challenge_type ?? SiteCertificate::CHALLENGE_HTTP,
                'domains_json' => $newHostnames,
                'status' => SiteCertificate::STATUS_PENDING,
                'requested_settings' => [
                    'source' => 'primary_hostname_rename',
                    'replaced_certificate_id' => (string) $source->id,
                ],
            ]);

            ExecuteSiteCertificateJob::dispatch($certificate->id);
        }
    }

    /**
     * @param  list<string>  $cascades  Opt-in cascade keys the operator selected
     *                                  (used to make audit payload self-describing).
     */
    private function recordRenameAudit(string $old, string $new, array $cascades, bool $dnsZoneRewritten): void
    {
        app(SiteAuditWriter::class)->record(
            site: $this->site,
            user: auth()->user(),
            action: 'site_primary_hostname_renamed',
            risk: RiskLevel::MutatingRecoverable,
            transport: SiteAuditEvent::TRANSPORT_WEB,
            summary: __('Primary hostname changed from :old to :new', ['old' => $old !== '' ? $old : '(none)', 'new' => $new]),
            payload: [
                'old_hostname' => $old,
                'new_hostname' => $new,
                'cascades' => $cascades,
                'dns_zone_rewritten' => $dnsZoneRewritten,
            ],
        );
    }

    /**
     * Bulk paste — one hostname per line. Lines that are blank or already
     * present (across any routing table) are skipped silently. Parse errors
     * abort the whole import (consistent with the env bulk import behavior).
     */
    public function bulkImportDomains(): void
    {
        $this->authorize('update', $this->site);
        $this->validate(['bulk_domain_input' => 'required|string|max:65535']);

        $lines = preg_split('/\r\n|\r|\n/', trim($this->bulk_domain_input)) ?: [];
        $hostnames = [];
        foreach ($lines as $i => $line) {
            $line = strtolower(trim($line));
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (! HostnameValidator::isValid($line)) {
                $this->addError('bulk_domain_input', sprintf('Line %d: "%s" is not a valid hostname.', $i + 1, $line));

                return;
            }
            $hostnames[] = $line;
        }

        $existing = SiteDomain::query()->whereIn('hostname', $hostnames)->pluck('hostname')->all();
        $imported = 0;
        foreach ($hostnames as $hostname) {
            if (in_array($hostname, $existing, true)) {
                continue;
            }
            SiteDomain::query()->create([
                'site_id' => $this->site->id,
                'hostname' => $hostname,
                'is_primary' => false,
                'www_redirect' => false,
            ]);
            $imported++;
        }

        $this->bulk_domain_input = '';
        $this->finalizeRoutingMutation(__(':count domain(s) imported.', ['count' => $imported]));
    }

    public function confirmRemoveDomain(int|string $domainId): void
    {
        $this->authorize('update', $this->site);

        $domain = SiteDomain::query()->where('site_id', $this->site->id)->find($domainId);
        $hostname = $domain?->hostname ?? __('this domain');

        $this->openConfirmActionModal(
            'removeDomain',
            [(string) $domainId],
            __('Remove :host', ['host' => $hostname]),
            __('Remove “:host” from :site? Its web-server config and any SSL certificate covering only this hostname are removed, and the site stops responding on it. Visitors hit this hostname will no longer reach the site. DNS records at your provider are not changed — delete those separately if you no longer use the domain. This cannot be undone.', [
                'host' => $hostname,
                'site' => $this->site->name,
            ]),
            __('Remove domain'),
            true,
        );
    }

    public function removeDomain(int|string $domainId): void
    {
        $this->authorize('update', $this->site);
        $domain = SiteDomain::query()->where('site_id', $this->site->id)->findOrFail($domainId);
        if ($domain->is_primary && $this->site->domains()->count() === 1) {
            $this->toastError('Cannot remove the only domain.');

            return;
        }
        if ($domain->hostname === $this->site->testingHostname()) {
            $this->toastError('The generated testing hostname is managed by Dply and cannot be removed here.');

            return;
        }
        if ($domain->is_primary) {
            $this->toastError('Set another domain as primary before removing the primary domain.');

            return;
        }
        $snapshot = [
            'domain_id' => (string) $domain->id,
            'hostname' => $domain->hostname,
            'is_primary' => (bool) $domain->is_primary,
        ];
        $domain->delete();

        $org = $this->site->server?->organization;
        if ($org) {
            audit_log($org, auth()->user(), 'site.domain.removed', $this->site, $snapshot, null);
        }

        $this->finalizeRoutingMutation('Domain removed.');
    }
}
