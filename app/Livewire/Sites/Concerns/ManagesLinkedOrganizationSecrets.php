<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Models\OrganizationSecret;
use App\Models\Site;
use App\Models\Workspace;
use App\Services\Sites\DotEnvFileParser;
use App\Support\Sites\OrganizationSecretException;
use App\Support\Sites\OrganizationSecretManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Link / unlink org vault secrets from a site Environment page.
 *
 * @phpstan-require-extends Component
 *
 * @property Site $site
 */
trait ManagesLinkedOrganizationSecrets
{
    public bool $showLinkOrganizationSecretModal = false;

    public string $linkSecretSearch = '';

    public ?string $linkSecretWorkspaceId = null;

    public string $pasteSecretKey = '';

    public string $pasteSecretValue = '';

    public string $pasteSecretNotes = '';

    public string $pasteSecretBlob = '';

    public function openLinkOrganizationSecretModal(): void
    {
        $this->authorize('update', $this->site);
        $this->showLinkOrganizationSecretModal = true;
        $this->linkSecretSearch = '';
        $this->linkSecretWorkspaceId = $this->site->workspace_id;
        $this->reset('pasteSecretKey', 'pasteSecretValue', 'pasteSecretNotes', 'pasteSecretBlob');
    }

    public function closeLinkOrganizationSecretModal(): void
    {
        $this->showLinkOrganizationSecretModal = false;
        $this->reset('pasteSecretKey', 'pasteSecretValue', 'pasteSecretNotes', 'pasteSecretBlob');
    }

    public function pasteOrganizationSecret(OrganizationSecretManager $manager): void
    {
        $this->authorize('update', $this->site);

        $org = $this->site->organization;
        if ($org === null) {
            $this->toastError(__('This site has no organization.'));

            return;
        }

        $blob = trim($this->pasteSecretBlob);
        if ($blob !== '') {
            $pairs = $manager->parsePastedEnv($blob);
            if ($pairs === []) {
                $this->addError('pasteSecretBlob', __('Could not parse any KEY=value lines from that .env snippet.'));

                return;
            }
        } else {
            $this->validate([
                'pasteSecretKey' => OrganizationSecretManager::KEY_RULE,
                'pasteSecretValue' => 'required|string|max:16000',
                'pasteSecretNotes' => 'nullable|string|max:2000',
            ]);
            $pairs = [[
                'key' => $this->pasteSecretKey,
                'value' => $this->pasteSecretValue,
                'note' => null,
            ]];
        }

        $fallbackNote = trim($this->pasteSecretNotes);
        $alreadyLinked = $this->site->organizationSecrets()
            ->pluck('organization_secret_sites.key')
            ->all();

        $linked = 0;
        $skipped = 0;
        try {
            DB::transaction(function () use ($manager, $org, $pairs, $fallbackNote, $alreadyLinked, &$linked, &$skipped): void {
                foreach ($pairs as $pair) {
                    if (in_array($pair['key'], $alreadyLinked, true)) {
                        $skipped++;

                        continue;
                    }

                    $note = $fallbackNote !== ''
                        ? $fallbackNote
                        : ($pair['note'] ?? __('Pasted on :site', ['site' => $this->site->name]));
                    $secret = $manager->create($org, $pair['key'], $pair['value'], $note, auth()->user());
                    $manager->link($this->site, $secret);
                    $alreadyLinked[] = $pair['key'];
                    $linked++;
                }
            });
        } catch (OrganizationSecretException $e) {
            $this->toastError($e->getMessage());

            return;
        }

        if ($linked === 0) {
            $this->toastError(__('Those keys are already linked on this site.'));

            return;
        }

        $this->reset('pasteSecretKey', 'pasteSecretValue', 'pasteSecretNotes', 'pasteSecretBlob');
        $this->showLinkOrganizationSecretModal = false;
        $this->dispatch('close-modal', 'link-organization-secret-modal');

        $message = trans_choice(
            '{1} Secret saved and linked. The value cannot be read back — it injects on the next deploy.|[2,*] :count secrets saved and linked. Values cannot be read back — they inject on the next deploy.',
            $linked,
            ['count' => $linked],
        );
        if ($skipped > 0) {
            $message .= ' '.__(':count already linked, skipped.', ['count' => $skipped]);
        }
        $this->toastSuccess($message);
    }

    /**
     * Keys that a pasted .env snippet will create. Values are never included.
     *
     * @return list<array{key: string, note: ?string, already_linked: bool}>
     */
    public function pastedSecretPreview(): array
    {
        $pairs = app(OrganizationSecretManager::class)->parsePastedEnv($this->pasteSecretBlob);
        $linkedKeys = $this->site->organizationSecrets()
            ->pluck('organization_secret_sites.key')
            ->all();

        return array_map(static fn (array $pair): array => [
            'key' => $pair['key'],
            'note' => $pair['note'],
            'already_linked' => in_array($pair['key'], $linkedKeys, true),
        ], $pairs);
    }

    public function linkOrganizationSecret(string $secretId, OrganizationSecretManager $manager): void
    {
        $this->authorize('update', $this->site);
        $secret = $this->secretInSiteOrg($secretId);

        try {
            $manager->link($this->site, $secret);
        } catch (OrganizationSecretException $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->showLinkOrganizationSecretModal = false;
        $this->dispatch('close-modal', 'link-organization-secret-modal');
        $this->toastSuccess(__('Secret linked. It applies on the next deploy.'));
    }

    public function unlinkOrganizationSecret(string $secretId, OrganizationSecretManager $manager): void
    {
        $this->authorize('update', $this->site);
        $secret = $this->secretInSiteOrg($secretId);
        $manager->unlink($this->site, $secret);
        $this->toastSuccess(__('Secret unlinked. The key drops on the next deploy.'));
    }

    /**
     * @return list<array{id: string, key: string, notes: ?string, overrides_site: bool, binding_owned: bool}>
     */
    public function linkedOrganizationSecretRows(): array
    {
        $siteKeys = $this->siteEnvKeys();
        $bindingKeys = app(OrganizationSecretManager::class)->bindingOwnedKeys($this->site);

        return $this->site->organizationSecrets()
            ->orderBy('organization_secrets.key')
            ->get()
            ->map(static fn (OrganizationSecret $secret): array => [
                'id' => $secret->id,
                'key' => $secret->key,
                'notes' => $secret->notes,
                'overrides_site' => in_array($secret->key, $siteKeys, true),
                'binding_owned' => in_array($secret->key, $bindingKeys, true),
            ])
            ->all();
    }

    /**
     * @return list<array{id: string, key: string, notes: ?string, already_linked: bool, key_taken: bool, binding_owned: bool}>
     */
    public function linkableOrganizationSecretRows(): array
    {
        $orgId = $this->site->organization_id;
        if (! is_string($orgId) || $orgId === '') {
            return [];
        }

        $linkedIds = $this->site->organizationSecrets()->pluck('organization_secrets.id')->all();
        $linkedKeys = $this->site->organizationSecrets()->pluck('organization_secret_sites.key')->all();
        $bindingKeys = app(OrganizationSecretManager::class)->bindingOwnedKeys($this->site);
        $search = strtolower(trim($this->linkSecretSearch));

        $query = OrganizationSecret::query()
            ->forListing()
            ->where('organization_id', $orgId)
            ->orderBy('key')
            ->orderBy('created_at');

        if ($search !== '') {
            $query->where(function ($inner) use ($search): void {
                $inner->whereRaw('lower(key) like ?', ['%'.$search.'%'])
                    ->orWhereRaw('lower(coalesce(notes, \'\')) like ?', ['%'.$search.'%']);
            });
        }

        if (filled($this->linkSecretWorkspaceId)) {
            $siteIds = Site::query()
                ->where('organization_id', $orgId)
                ->where('workspace_id', $this->linkSecretWorkspaceId)
                ->pluck('id');
            $query->where(function ($inner) use ($siteIds): void {
                $inner->whereDoesntHave('sites')
                    ->orWhereHas('sites', fn ($sites) => $sites->whereIn('sites.id', $siteIds));
            });
        }

        return $query->get()
            ->map(static fn (OrganizationSecret $secret): array => [
                'id' => $secret->id,
                'key' => $secret->key,
                'notes' => $secret->notes,
                'already_linked' => in_array($secret->id, $linkedIds, true),
                'key_taken' => in_array($secret->key, $linkedKeys, true) && ! in_array($secret->id, $linkedIds, true),
                'binding_owned' => in_array($secret->key, $bindingKeys, true),
            ])
            ->all();
    }

    /** @return Collection<int, Workspace> */
    public function linkSecretWorkspaceOptions(): Collection
    {
        $orgId = $this->site->organization_id;
        if (! is_string($orgId) || $orgId === '') {
            return collect();
        }

        return Workspace::query()
            ->where('organization_id', $orgId)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * @return list<string>
     */
    private function siteEnvKeys(): array
    {
        $content = (string) ($this->site->env_file_content ?? '');
        if ($this->site->usesEdgeRuntime()) {
            return $this->site->edgeEnvVars()->pluck('key')->map(fn ($key) => (string) $key)->all();
        }

        $parsed = app(DotEnvFileParser::class)->parse($content);

        return array_keys($parsed['variables']);
    }

    private function secretInSiteOrg(string $secretId): OrganizationSecret
    {
        return OrganizationSecret::query()
            ->where('organization_id', $this->site->organization_id)
            ->whereKey($secretId)
            ->firstOrFail();
    }
}
