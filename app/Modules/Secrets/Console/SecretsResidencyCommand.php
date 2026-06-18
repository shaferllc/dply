<?php

declare(strict_types=1);

namespace App\Modules\Secrets\Console;

use App\Models\ExternalSecretStore;
use App\Models\Site;
use App\Models\SiteSecretResidency;
use App\Services\Sites\SecretEscalator;
use Illuminate\Console\Command;

/**
 * Drive a site's secret residency from the CLI — escalate a var into escrow,
 * list what's escalated, reveal a value, or pull one back out. The customer UI
 * (Site → Environment) is a later PR; this is the operator/testing surface and
 * proves the end-to-end round-trip.
 */
class SecretsResidencyCommand extends Command
{
    protected $signature = 'secrets:residency
        {action : escalate | escalate-external | list | reveal | demote}
        {site : site id}
        {key? : env var name (escalate/reveal/demote/escalate-external)}
        {--value= : plaintext to escrow on escalate (defaults to the current loose-env value)}
        {--identity= : age identity to decrypt with, for a customer-held org key}
        {--store= : external secret store id (escalate-external)}
        {--reference= : reference within the store, e.g. secret/data/stripe#key (escalate-external)}';

    protected $description = 'Manage per-key secret residency (escrow) for a site.';

    public function handle(SecretEscalator $escalator): int
    {
        $site = Site::find((string) $this->argument('site'));
        if ($site === null) {
            $this->error('Site not found.');

            return self::FAILURE;
        }

        $action = (string) $this->argument('action');
        $key = $this->argument('key') !== null ? (string) $this->argument('key') : null;
        $identity = $this->option('identity') !== null ? (string) $this->option('identity') : null;

        try {
            return match ($action) {
                'list' => $this->list($site),
                'escalate' => $this->escalate($escalator, $site, $this->requireKey($key)),
                'escalate-external' => $this->escalateExternal($escalator, $site, $this->requireKey($key)),
                'reveal' => $this->reveal($escalator, $site, $this->requireKey($key), $identity),
                'demote' => $this->demote($escalator, $site, $this->requireKey($key), $identity),
                default => $this->failWith("Unknown action '{$action}' (escalate|list|reveal|demote)."),
            };
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function list(Site $site): int
    {
        $rows = $site->secretResidencies()->orderBy('key')->get();
        if ($rows->isEmpty()) {
            $this->info('No escalated secrets for this site.');

            return self::SUCCESS;
        }

        $this->table(
            ['key', 'mode', 'placeholder'],
            $rows->map(fn (SiteSecretResidency $r): array => [$r->key, $r->mode, $r->placeholder()])->all(),
        );

        return self::SUCCESS;
    }

    private function escalate(SecretEscalator $escalator, Site $site, string $key): int
    {
        $value = $this->option('value') !== null ? (string) $this->option('value') : null;
        $residency = $escalator->escalate($site, $key, $value);
        $this->info("Escalated {$key} → escrow ({$residency->placeholder()}).");
        $this->line('  The loose .env now holds only the placeholder; push to apply on the server.');

        return self::SUCCESS;
    }

    private function escalateExternal(SecretEscalator $escalator, Site $site, string $key): int
    {
        $storeId = trim((string) ($this->option('store') ?? ''));
        $reference = trim((string) ($this->option('reference') ?? ''));
        if ($storeId === '' || $reference === '') {
            throw new \RuntimeException('escalate-external requires --store and --reference.');
        }

        $store = ExternalSecretStore::find($storeId);
        if ($store === null) {
            throw new \RuntimeException("External store '{$storeId}' not found.");
        }

        $residency = $escalator->escalateToExternal($site, $key, $store, $reference);
        $this->info("Pointed {$key} at {$store->driver}:{$reference} ({$residency->placeholder()}).");
        $this->line('  No value entered dply; push to resolve and apply on the server.');

        return self::SUCCESS;
    }

    private function reveal(SecretEscalator $escalator, Site $site, string $key, ?string $identity): int
    {
        $residency = $this->findResidency($site, $key);
        $this->line($escalator->reveal($residency, $identity));

        return self::SUCCESS;
    }

    private function demote(SecretEscalator $escalator, Site $site, string $key, ?string $identity): int
    {
        $residency = $this->findResidency($site, $key);
        $escalator->demote($site, $residency, $identity);
        $this->info("Demoted {$key} back into the loose .env.");

        return self::SUCCESS;
    }

    private function findResidency(Site $site, string $key): SiteSecretResidency
    {
        $residency = $site->secretResidencies()->where('key', $key)->first();
        if ($residency === null) {
            throw new \RuntimeException("'{$key}' is not escalated for this site.");
        }

        return $residency;
    }

    private function requireKey(?string $key): string
    {
        if ($key === null || $key === '') {
            throw new \RuntimeException('This action requires a {key} argument.');
        }

        return $key;
    }

    private function failWith(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
