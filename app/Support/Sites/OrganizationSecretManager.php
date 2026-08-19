<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\Organization;
use App\Models\OrganizationSecret;
use App\Models\Site;
use App\Models\User;
use App\Services\Sites\DotEnvFileParser;

/**
 * Create / rotate / delete org vault secrets and link them onto sites.
 * Livewire never reads {@see OrganizationSecret::$value} after save.
 *
 * @see docs/ORG_SHARED_SECRETS.md
 */
final class OrganizationSecretManager
{
    public const KEY_RULE = 'required|string|max:128|regex:/^[A-Za-z_][A-Za-z0-9_]*$/';

    /**
     * Parse a pasted `.env` snippet (section banners, comments, `export`,
     * quoted values). Empty values are skipped. Last assignment wins.
     *
     * @return list<array{key: string, value: string, note: ?string}>
     */
    public function parsePastedEnv(string $blob): array
    {
        $parsed = app(DotEnvFileParser::class)->parse($blob);
        $pairs = [];
        $section = null;

        foreach ($parsed['variables'] as $rawKey => $value) {
            $key = $this->normalizeKey((string) $rawKey);
            $value = (string) $value;
            if ($key === '' || $value === '' || ! preg_match('/^[A-Z_][A-Z0-9_]*$/', $key)) {
                continue;
            }

            $comment = $parsed['comments'][$rawKey] ?? null;
            if (is_string($comment)) {
                $label = $this->sectionLabelFromComment($comment);
                if ($label !== null) {
                    $section = $label;
                }
            }

            $pairs[$key] = [
                'key' => $key,
                'value' => $value,
                'note' => $section,
            ];
        }

        return array_values($pairs);
    }

    public function create(Organization $organization, string $key, string $value, ?string $notes, ?User $actor = null): OrganizationSecret
    {
        $key = $this->normalizeKey($key);
        $notes = $this->normalizeNotes($notes);
        $this->assertNotesWhenKeyExists($organization, $key, $notes);

        return OrganizationSecret::query()->create([
            'organization_id' => $organization->id,
            'created_by_user_id' => $actor?->id,
            'key' => $key,
            'value' => $value,
            'notes' => $notes,
        ]);
    }

    public function rotate(OrganizationSecret $secret, string $value): void
    {
        $secret->update(['value' => $value]);
    }

    public function updateNotes(OrganizationSecret $secret, ?string $notes): void
    {
        $secret->update(['notes' => $this->normalizeNotes($notes)]);
    }

    public function delete(OrganizationSecret $secret): void
    {
        $secret->delete();
    }

    public function link(Site $site, OrganizationSecret $secret): void
    {
        if ((string) $secret->organization_id !== (string) $site->organization_id) {
            throw new OrganizationSecretException(__('That secret does not belong to this site\'s organization.'));
        }

        $already = $site->organizationSecrets()
            ->where('organization_secret_sites.key', $secret->key)
            ->exists();

        if ($already) {
            throw new OrganizationSecretException(__('This site already has a secret linked for :key.', [
                'key' => $secret->key,
            ]));
        }

        $site->organizationSecrets()->attach($secret->id, [
            'key' => $secret->key,
        ]);
    }

    public function unlink(Site $site, OrganizationSecret $secret): void
    {
        $site->organizationSecrets()->detach($secret->id);
    }

    /**
     * @return list<string>
     */
    public function bindingOwnedKeys(Site $site): array
    {
        $keys = [];
        $source = $site->resourceSourceSite();
        $source->loadMissing('bindings');

        foreach ($source->bindings as $binding) {
            foreach (array_keys($binding->connectionEnv()) as $key) {
                $keys[] = (string) $key;
            }
        }

        return array_values(array_unique($keys));
    }

    private function assertNotesWhenKeyExists(Organization $organization, string $key, ?string $notes): void
    {
        $exists = OrganizationSecret::query()
            ->where('organization_id', $organization->id)
            ->where('key', $key)
            ->exists();

        if ($exists && $notes === null) {
            throw new OrganizationSecretException(__('Add a note so this :key can be told apart from the existing one.', [
                'key' => $key,
            ]));
        }
    }

    private function sectionLabelFromComment(string $comment): ?string
    {
        $labels = [];
        foreach (preg_split('/\R/', $comment) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || preg_match('/^[=*-]+$/', $line) === 1) {
                continue;
            }
            $labels[] = $line;
        }

        if ($labels === []) {
            return null;
        }

        return implode(' · ', $labels);
    }

    private function normalizeKey(string $key): string
    {
        return strtoupper(trim($key));
    }

    private function normalizeNotes(?string $notes): ?string
    {
        $notes = is_string($notes) ? trim($notes) : '';

        return $notes === '' ? null : $notes;
    }
}
