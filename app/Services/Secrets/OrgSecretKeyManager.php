<?php

declare(strict_types=1);

namespace App\Services\Secrets;

use App\Models\OrgSecretKey;
use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * Owns an organization's {@see OrgSecretKey} — the per-org `age` keypair used to
 * encrypt that org's escrowed secrets. This is the "recipient resolver" for the
 * secret-residency engine: given an org it yields the recipient to encrypt to,
 * and (when dply holds the identity) decrypts ciphertext back.
 *
 * v1 mints DPLY-HELD keys: a distinct key per org for blast-radius isolation —
 * dply can still decrypt. Customer-held keys (true ZK at rest) are a later PR;
 * {@see decrypt()} already requires an explicit identity for that path.
 */
class OrgSecretKeyManager
{
    public function __construct(private readonly AgeEncryptor $age) {}

    /**
     * The org's key, minting a fresh dply-held keypair on first use. Idempotent
     * and race-safe (the unique org_id constraint collapses a concurrent mint).
     */
    public function ensureForOrg(string $organizationId): OrgSecretKey
    {
        $existing = OrgSecretKey::query()->where('organization_id', $organizationId)->first();
        if ($existing !== null) {
            return $existing;
        }

        $kp = $this->age->generateKeypair();

        try {
            return OrgSecretKey::create([
                'organization_id' => $organizationId,
                'public_recipient' => $kp['recipient'],
                'identity_holder' => OrgSecretKey::HOLDER_DPLY,
                'dply_identity' => $kp['identity'],
                'fingerprint' => substr(hash('sha256', $kp['recipient']), 0, 12),
            ]);
        } catch (QueryException $e) {
            // Lost a mint race — the other writer's row is canonical.
            $raced = OrgSecretKey::query()->where('organization_id', $organizationId)->first();
            if ($raced !== null) {
                return $raced;
            }
            throw $e;
        }
    }

    /** The recipient string to encrypt this org's secrets to (mints if needed). */
    public function recipientFor(string $organizationId): string
    {
        return $this->ensureForOrg($organizationId)->public_recipient;
    }

    /**
     * Adopt a customer-SUPPLIED recipient as the org's key (BYO-key). dply stores
     * only the public recipient and never holds an identity — it can encrypt new
     * secrets to the customer but cannot decrypt them. Requires the org to have
     * no escrowed secrets under a previous key it can no longer open (the caller
     * is responsible for that check, since switching keys orphans old ciphertext).
     */
    public function adoptCustomerRecipient(string $organizationId, string $recipient): OrgSecretKey
    {
        $recipient = trim($recipient);
        if (! preg_match('/^age1[0-9a-z]+$/', $recipient)) {
            throw new RuntimeException('Not a valid age recipient (expected "age1…").');
        }

        $key = OrgSecretKey::query()->where('organization_id', $organizationId)->first()
            ?? new OrgSecretKey(['organization_id' => $organizationId]);

        $key->forceFill([
            'organization_id' => $organizationId,
            'public_recipient' => $recipient,
            'identity_holder' => OrgSecretKey::HOLDER_CUSTOMER,
            'dply_identity' => null,
            'fingerprint' => substr(hash('sha256', $recipient), 0, 12),
        ])->save();

        return $key;
    }

    /**
     * Convert an org to customer-held by MINTING a fresh keypair, returning the
     * private identity to the caller exactly ONCE (to hand to the customer), then
     * persisting only the public recipient. After this dply cannot decrypt this
     * org's secrets — the returned identity is the only copy.
     *
     * Re-encrypts nothing: callers should run this on an org with no escrowed
     * secrets yet, or re-escalate afterwards under the new recipient.
     *
     * @return array{key: OrgSecretKey, identity: string} identity = show-once
     */
    public function promoteToCustomerHeld(string $organizationId): array
    {
        $kp = $this->age->generateKeypair();
        $key = $this->adoptCustomerRecipient($organizationId, $kp['recipient']);

        return ['key' => $key, 'identity' => $kp['identity']];
    }

    /** age-encrypt a value to the org's recipient. */
    public function encryptForOrg(string $organizationId, string $plaintext): string
    {
        return $this->age->encryptTo($plaintext, [$this->recipientFor($organizationId)]);
    }

    /**
     * Decrypt ciphertext that was encrypted to this org's key.
     *
     * @param  string|null  $ephemeralIdentity  required when the customer holds
     *                                          the identity (dply has none); ignored for dply-held keys. Never persisted.
     */
    public function decrypt(OrgSecretKey $key, string $ciphertext, ?string $ephemeralIdentity = null): string
    {
        if ($ephemeralIdentity !== null && trim($ephemeralIdentity) !== '') {
            return $this->age->decryptWith($ciphertext, $ephemeralIdentity);
        }

        if (! $key->dplyCanDecrypt()) {
            throw new RuntimeException(
                "org {$key->organization_id} holds its own secret key — supply the identity to decrypt (dply cannot)."
            );
        }

        return $this->age->decryptWith($ciphertext, (string) $key->dply_identity);
    }
}
