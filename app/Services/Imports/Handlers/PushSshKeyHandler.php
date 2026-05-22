<?php

declare(strict_types=1);

namespace App\Services\Imports\Handlers;

use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\ProviderCredential;
use App\Services\Imports\ImportDriver;
use App\Services\Imports\SourceDriverFactory;
use App\Services\Imports\StepHandler;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use phpseclib3\Crypt\EC;
use RuntimeException;

/**
 * Generates a per-migration ephemeral ed25519 keypair (Q5 decision), pushes
 * the public key to the source server via the import driver's SSH-keys
 * endpoint, and persists the encrypted private key + source-side key id on
 * the parent ImportServerMigration so the corresponding RevokeSshKeyHandler
 * has everything it needs to clean up later.
 *
 * Idempotency: if ssh_key_source_id is already set on the parent, treat the
 * step as a no-op success — a re-run after a crash mid-step shouldn't push
 * a second key. The trust posture (Q5) is "exactly one key per migration."
 */
class PushSshKeyHandler implements StepHandler
{
    public function __construct(protected SourceDriverFactory $drivers) {}

    public static function key(): string
    {
        return ImportMigrationStep::KEY_PUSH_SSH_KEY;
    }

    public function execute(ImportMigrationStep $step): void
    {
        $migration = ImportServerMigration::find($step->import_server_migration_id);
        if ($migration === null) {
            throw new RuntimeException('Parent migration disappeared before push_ssh_key ran.');
        }

        if ($migration->ssh_key_source_id !== null) {
            return; // already pushed in a prior attempt
        }

        $credential = ProviderCredential::find($migration->provider_credential_id);
        if ($credential === null) {
            throw new RuntimeException('Provider credential missing for migration '.$migration->id);
        }

        [$privateOpenSsh, $publicOpenSsh, $fingerprint] = $this->generateEphemeralKeypair($migration->id);
        $driver = $this->driverFor($credential);
        $label = $this->keyLabel($migration->id);

        $sourceKeyId = $driver->pushSshKey($migration->source_server_id, $label, $publicOpenSsh);

        $migration->ssh_key_public = $publicOpenSsh;
        $migration->ssh_key_private_encrypted = Crypt::encryptString($privateOpenSsh);
        $migration->ssh_key_fingerprint = $fingerprint;
        $migration->ssh_key_source_id = $sourceKeyId;
        $migration->ssh_key_pushed_at = Carbon::now();
        $migration->save();

        $step->result_data = ['ssh_key_source_id' => $sourceKeyId];
        $step->save();
    }

    /**
     * @return array{0: string, 1: string, 2: string} private OpenSSH, public OpenSSH, sha256 fingerprint
     */
    protected function generateEphemeralKeypair(string $migrationId): array
    {
        $key = EC::createKey('Ed25519');
        $privateOpenSsh = (string) $key->toString('OpenSSH');
        $publicOpenSsh = (string) $key->getPublicKey()->toString('OpenSSH', [
            'comment' => $this->keyLabel($migrationId),
        ]);
        $fingerprint = (string) $key->getPublicKey()->getFingerprint('sha256');

        return [$privateOpenSsh, $publicOpenSsh, $fingerprint];
    }

    protected function keyLabel(string $migrationId): string
    {
        return 'dply-migrate-'.Str::lower(Str::substr($migrationId, -10));
    }

    protected function driverFor(ProviderCredential $credential): ImportDriver
    {
        return $this->drivers->for($credential);
    }
}
