<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Models\SiteDomain;
use App\Models\SiteDomainAlias;
use App\Models\SitePreviewDomain;
use App\Models\SiteTenantDomain;
use App\Support\HostnameValidator;
use Illuminate\Validation\Rule;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesSiteAliases
{
    public string $new_alias_hostname = '';

    public string $new_alias_label = '';

    public string $new_alias_comment = '';

    /** Multi-line bulk paste — `hostname` or `hostname,label` per line. */
    public string $bulk_alias_input = '';

    /** When non-null, the aliases list shows an inline edit form for this row. */
    public ?string $editing_alias_id = null;

    public string $editing_alias_hostname = '';

    public string $editing_alias_label = '';

    public string $editing_alias_comment = '';

    public function addAlias(): void
    {
        $this->authorize('update', $this->site);

        $validated = $this->validate([
            'new_alias_hostname' => [
                'required',
                'string',
                'max:255',
                Rule::unique('site_domain_aliases', 'hostname'),
                Rule::unique('site_domains', 'hostname'),
                Rule::unique('site_preview_domains', 'hostname'),
                Rule::unique('site_tenant_domains', 'hostname'),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || ! HostnameValidator::isValid($value)) {
                        $fail('Enter a valid alias like www.example.com.');
                    }
                },
            ],
            'new_alias_label' => ['nullable', 'string', 'max:255'],
            'new_alias_comment' => ['nullable', 'string', 'max:2000'],
        ]);

        SiteDomainAlias::query()->create([
            'site_id' => $this->site->id,
            'hostname' => strtolower(trim($validated['new_alias_hostname'])),
            'label' => trim((string) ($validated['new_alias_label'] ?? '')) ?: null,
            'comment' => trim((string) ($validated['new_alias_comment'] ?? '')) ?: null,
            'sort_order' => (int) ($this->site->domainAliases()->max('sort_order') ?? 0) + 1,
        ]);

        $this->new_alias_hostname = '';
        $this->new_alias_label = '';
        $this->new_alias_comment = '';
        $this->site->load('domainAliases');
        $this->finalizeRoutingMutation('Alias added.');
    }

    public function confirmRemoveAlias(string $aliasId): void
    {
        $this->authorize('update', $this->site);
        $this->openConfirmActionModal(
            'removeAlias',
            [$aliasId],
            __('Remove alias'),
            __('Remove this alias from the webserver server_name list?'),
            __('Remove alias'),
            true,
        );
    }

    public function removeAlias(string $aliasId): void
    {
        $this->authorize('update', $this->site);

        $this->site->domainAliases()->findOrFail($aliasId)->delete();
        $this->site->load('domainAliases');
        $this->finalizeRoutingMutation('Alias removed.');
    }

    public function editAlias(string $aliasId): void
    {
        $this->authorize('update', $this->site);
        $alias = $this->site->domainAliases()->findOrFail($aliasId);
        $this->editing_alias_id = (string) $alias->id;
        $this->editing_alias_hostname = (string) $alias->hostname;
        $this->editing_alias_label = (string) ($alias->label ?? '');
        $this->editing_alias_comment = (string) ($alias->comment ?? '');
    }

    public function cancelEditAlias(): void
    {
        $this->editing_alias_id = null;
        $this->editing_alias_hostname = '';
        $this->editing_alias_label = '';
        $this->editing_alias_comment = '';
    }

    public function saveEditedAlias(): void
    {
        $this->authorize('update', $this->site);
        if ($this->editing_alias_id === null) {
            return;
        }
        $alias = $this->site->domainAliases()->findOrFail($this->editing_alias_id);
        $this->validate([
            'editing_alias_hostname' => [
                'required',
                'string',
                'max:255',
                Rule::unique('site_domain_aliases', 'hostname')->ignore($alias->id),
                Rule::unique('site_domains', 'hostname'),
                Rule::unique('site_preview_domains', 'hostname'),
                Rule::unique('site_tenant_domains', 'hostname'),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || ! HostnameValidator::isValid($value)) {
                        $fail('Enter a valid alias like www.example.com.');
                    }
                },
            ],
            'editing_alias_label' => ['nullable', 'string', 'max:255'],
            'editing_alias_comment' => ['nullable', 'string', 'max:2000'],
        ]);
        $alias->forceFill([
            'hostname' => strtolower(trim($this->editing_alias_hostname)),
            'label' => trim($this->editing_alias_label) ?: null,
            'comment' => trim($this->editing_alias_comment) ?: null,
        ])->save();

        $this->cancelEditAlias();
        $this->site->load('domainAliases');
        $this->finalizeRoutingMutation('Alias updated.');
    }

    /**
     * Bulk paste aliases — `hostname` or `hostname,label` per line. Existing
     * hostnames (in any routing table) are silently skipped to make repeated
     * pastes safe.
     */
    public function bulkImportAliases(): void
    {
        $this->authorize('update', $this->site);
        $this->validate(['bulk_alias_input' => 'required|string|max:65535']);

        $lines = preg_split('/\r\n|\r|\n/', trim($this->bulk_alias_input)) ?: [];
        $rows = [];
        foreach ($lines as $i => $rawLine) {
            $line = trim($rawLine);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = array_map('trim', explode(',', $line, 2));
            $hostname = strtolower($parts[0] ?? '');
            $label = $parts[1] ?? null;
            if ($hostname === '' || ! HostnameValidator::isValid($hostname)) {
                $this->addError('bulk_alias_input', sprintf('Line %d: "%s" is not a valid hostname.', $i + 1, $hostname));

                return;
            }
            $rows[] = ['hostname' => $hostname, 'label' => $label];
        }

        // Filter out hostnames already present anywhere in the routing
        // namespace (domains, aliases, preview, tenants). Skipping silently
        // keeps `paste a snapshot from prod` ergonomic.
        $taken = collect()
            ->merge(SiteDomain::query()->pluck('hostname'))
            ->merge(SiteDomainAlias::query()->pluck('hostname'))
            ->merge(SitePreviewDomain::query()->pluck('hostname'))
            ->merge(SiteTenantDomain::query()->pluck('hostname'))
            ->map(fn ($h) => strtolower((string) $h))
            ->unique()
            ->all();

        $sortBase = (int) ($this->site->domainAliases()->max('sort_order') ?? 0);
        $imported = 0;
        foreach ($rows as $row) {
            if (in_array($row['hostname'], $taken, true)) {
                continue;
            }
            SiteDomainAlias::query()->create([
                'site_id' => $this->site->id,
                'hostname' => $row['hostname'],
                'label' => $row['label'] !== null && $row['label'] !== '' ? $row['label'] : null,
                'sort_order' => ++$sortBase,
            ]);
            $imported++;
        }

        $this->bulk_alias_input = '';
        $this->site->load('domainAliases');
        $this->finalizeRoutingMutation(__(':count alias(es) imported.', ['count' => $imported]));
    }
}
