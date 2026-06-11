<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\OrgSecretKey;
use App\Services\Secrets\OrgSecretKeyManager;
use Illuminate\Console\Command;

/**
 * Inspect and set an organization's secret key holder (see OrgSecretKey).
 *
 *   show     — current holder, recipient, fingerprint, whether dply can decrypt
 *   ensure   — mint a dply-held key if none exists
 *   promote  — switch to CUSTOMER-held by minting a keypair and printing the
 *              identity ONCE (dply keeps only the recipient afterwards)
 *   adopt    — switch to CUSTOMER-held using a customer-supplied recipient
 */
class SecretsOrgKeyCommand extends Command
{
    protected $signature = 'secrets:org-key
        {action : show | ensure | promote | adopt}
        {org : organization id}
        {recipient? : age recipient (required for adopt)}';

    protected $description = "Manage an organization's secret encryption key (dply-held or customer-held).";

    public function handle(OrgSecretKeyManager $manager): int
    {
        $orgId = (string) $this->argument('org');
        $action = (string) $this->argument('action');

        try {
            return match ($action) {
                'show' => $this->show($orgId),
                'ensure' => $this->describe($manager->ensureForOrg($orgId), 'Ensured dply-held key.'),
                'promote' => $this->promote($manager, $orgId),
                'adopt' => $this->adopt($manager, $orgId),
                default => $this->bail("Unknown action '{$action}' (show|ensure|promote|adopt)."),
            };
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function show(string $orgId): int
    {
        $key = OrgSecretKey::query()->where('organization_id', $orgId)->first();
        if ($key === null) {
            $this->warn('No secret key for this org yet (one is minted dply-held on first escrow).');

            return self::SUCCESS;
        }

        return $this->describe($key, 'Current key:');
    }

    private function promote(OrgSecretKeyManager $manager, string $orgId): int
    {
        $result = $manager->promoteToCustomerHeld($orgId);
        $this->describe($result['key'], 'Promoted to customer-held.');
        $this->newLine();
        $this->warn('Save this identity now — dply does NOT keep a copy. You must supply it to deploy:');
        $this->line($result['identity']);

        return self::SUCCESS;
    }

    private function adopt(OrgSecretKeyManager $manager, string $orgId): int
    {
        $recipient = (string) ($this->argument('recipient') ?? '');
        if ($recipient === '') {
            $this->error('adopt requires a {recipient} (age1…).');

            return self::FAILURE;
        }

        return $this->describe($manager->adoptCustomerRecipient($orgId, $recipient), 'Adopted customer recipient.');
    }

    private function describe(OrgSecretKey $key, string $heading): int
    {
        $this->info($heading);
        $this->table(['holder', 'recipient', 'fingerprint', 'dply can decrypt'], [[
            $key->identity_holder,
            $key->public_recipient,
            (string) $key->fingerprint,
            $key->dplyCanDecrypt() ? 'yes' : 'no',
        ]]);

        return self::SUCCESS;
    }

    private function bail(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
