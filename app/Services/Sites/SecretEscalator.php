<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Models\ExternalSecretStore;
use App\Models\Site;
use App\Models\SiteSecretResidency;
use App\Services\Secrets\OrgSecretKeyManager;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Moves a single env var into — and back out of — escrow residency: the value
 * leaves the plaintext-in-DB `.env` blob and becomes an `age` blob under the
 * org's key ({@see SiteSecretResidency} escrow mode), with only a placeholder
 * left behind in the loose env. {@see \App\Services\Sites\SecretResidencyResolver}
 * swaps the placeholder back for the real value at push/deploy.
 *
 * Escalation only changes WHERE the secret lives; the deployed app is unaffected
 * until the next env push (which resolves the placeholder).
 */
class SecretEscalator
{
    public function __construct(
        protected DotEnvFileParser $parser,
        protected DotEnvFileWriter $writer,
        protected OrgSecretKeyManager $orgKeys,
    ) {}

    /**
     * Escalate an env key to escrow. Encrypts its value to the org's recipient,
     * records the residency, and replaces the loose-env value with a placeholder.
     *
     * @param  string|null  $value  the plaintext to escrow; when null, the key's
     *   current value in the loose env is used (escalate an existing var).
     */
    public function escalate(Site $site, string $key, ?string $value = null): SiteSecretResidency
    {
        $orgId = $site->organization_id;
        if ($orgId === null || $orgId === '') {
            throw new RuntimeException("site {$site->id} has no organization — cannot escalate a secret.");
        }

        $parsed = $this->parser->parse((string) $site->env_file_content);
        if ($parsed['errors'] !== []) {
            throw new RuntimeException('.env has parse errors — fix before escalating: '.implode('; ', $parsed['errors']));
        }

        $plaintext = $value ?? ($parsed['variables'][$key] ?? null);
        if ($plaintext === null) {
            throw new RuntimeException("'{$key}' is not set in this site's environment — provide a value to escalate.");
        }

        $ciphertext = $this->orgKeys->encryptForOrg($orgId, $plaintext);

        return DB::transaction(function () use ($site, $key, $ciphertext, $parsed): SiteSecretResidency {
            $residency = SiteSecretResidency::updateOrCreate(
                ['site_id' => $site->id, 'key' => $key],
                [
                    'mode' => SiteSecretResidency::MODE_ESCROW,
                    'ciphertext' => $ciphertext,
                    'store_id' => null,
                    'reference' => null,
                ],
            );

            $vars = $parsed['variables'];
            $vars[$key] = $residency->placeholder();
            $site->forceFill(['env_file_content' => $this->writer->render($vars, $parsed['comments'])])->save();

            return $residency;
        });
    }

    /**
     * Point an env key at an external store reference (Tier 3). Unlike escrow,
     * NO value enters dply — only the pointer is stored, and the loose env keeps
     * just the placeholder. The store must belong to the site's org.
     */
    public function escalateToExternal(Site $site, string $key, ExternalSecretStore $store, string $reference): SiteSecretResidency
    {
        if ($store->organization_id !== $site->organization_id) {
            throw new RuntimeException('external store belongs to a different organization than the site.');
        }
        $reference = trim($reference);
        if ($reference === '') {
            throw new RuntimeException('a reference is required to point a key at an external store.');
        }

        $parsed = $this->parser->parse((string) $site->env_file_content);
        if ($parsed['errors'] !== []) {
            throw new RuntimeException('.env has parse errors — fix before escalating: '.implode('; ', $parsed['errors']));
        }

        return DB::transaction(function () use ($site, $key, $store, $reference, $parsed): SiteSecretResidency {
            $residency = SiteSecretResidency::updateOrCreate(
                ['site_id' => $site->id, 'key' => $key],
                [
                    'mode' => SiteSecretResidency::MODE_EXTERNAL,
                    'ciphertext' => null,
                    'store_id' => $store->id,
                    'reference' => $reference,
                ],
            );

            $vars = $parsed['variables'];
            $vars[$key] = $residency->placeholder();
            $site->forceFill(['env_file_content' => $this->writer->render($vars, $parsed['comments'])])->save();

            return $residency;
        });
    }

    /**
     * Reveal an escalated secret's plaintext (e.g. for the operator's eyes).
     * For a customer-held org key, `$ephemeralIdentity` must be supplied.
     */
    public function reveal(SiteSecretResidency $residency, ?string $ephemeralIdentity = null): string
    {
        $this->assertEscrow($residency);
        $orgKey = $this->orgKeys->ensureForOrg($this->orgIdFor($residency));

        return $this->orgKeys->decrypt($orgKey, (string) $residency->ciphertext, $ephemeralIdentity);
    }

    /**
     * Pull a secret back OUT of escrow into the loose env (decrypt, write the
     * real value back, drop the residency row). Requires the org identity.
     */
    public function demote(Site $site, SiteSecretResidency $residency, ?string $ephemeralIdentity = null): void
    {
        $this->assertEscrow($residency);
        $plaintext = $this->reveal($residency, $ephemeralIdentity);

        $parsed = $this->parser->parse((string) $site->env_file_content);
        $vars = $parsed['variables'];
        $vars[$residency->key] = $plaintext;

        DB::transaction(function () use ($site, $residency, $vars, $parsed): void {
            $site->forceFill(['env_file_content' => $this->writer->render($vars, $parsed['comments'])])->save();
            $residency->delete();
        });
    }

    private function assertEscrow(SiteSecretResidency $residency): void
    {
        if ($residency->mode !== SiteSecretResidency::MODE_ESCROW) {
            throw new RuntimeException("residency '{$residency->key}' is not in escrow mode.");
        }
    }

    private function orgIdFor(SiteSecretResidency $residency): string
    {
        $orgId = $residency->site?->organization_id;
        if ($orgId === null || $orgId === '') {
            throw new RuntimeException("residency '{$residency->key}' has no resolvable organization.");
        }

        return $orgId;
    }
}
