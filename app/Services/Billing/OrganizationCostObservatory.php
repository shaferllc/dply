<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Organization;
use App\Models\Server;
use App\Services\Servers\ProviderCostUnavailableException;
use App\Services\Servers\ServerProviderCostEstimator;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Org-wide cost observatory — Dply platform fees, estimated provider
 * infrastructure, and an honest comparison baseline (Forge per-server).
 */
final class OrganizationCostObservatory
{
    public function __construct(
        private readonly ServerMonthlyCostNoteParser $noteParser,
        private readonly ServerProviderCostEstimator $providerCostEstimator,
    ) {}

    /**
     * @return array<string, mixed>
     */
    /** @return array<string, mixed> */
    public function forOrganization(Organization $organization, DesiredBillingState $state): array
    {
        $servers = $this->infrastructureServers($organization);
        $serverRows = $servers->map(fn (Server $server): array => $this->serverRow($server))->values()->all();

        $knownProviderCents = (int) collect($serverRows)->sum('monthly_usd_cents');
        $unknownCount = (int) collect($serverRows)->where('source', 'unknown')->count();
        $dplyCents = $state->monthlyTotalCents;

        $forgePerServer = (int) config('subscription.observatory.forge_per_server_cents', 1200);
        $forgeServerCount = max(1, $state->serverCount());
        $forgeBaselineCents = $forgePerServer * $forgeServerCount;

        return [
            'dply_platform_cents' => $dplyCents,
            'provider_infrastructure_cents' => $knownProviderCents,
            'stack_total_cents' => $dplyCents + $knownProviderCents,
            'provider_partial' => $unknownCount > 0,
            'provider_unknown_count' => $unknownCount,
            'provider_server_count' => count($serverRows),
            'servers' => $serverRows,
            'comparison' => [
                'forge_per_server_cents' => $forgePerServer,
                'forge_baseline_cents' => $forgeBaselineCents,
                'forge_plus_provider_cents' => $forgeBaselineCents + $knownProviderCents,
                'dply_plus_provider_cents' => $dplyCents + $knownProviderCents,
                'delta_vs_forge_cents' => ($dplyCents + $knownProviderCents) - ($forgeBaselineCents + $knownProviderCents),
            ],
            'disclaimer' => __('Provider costs are catalog estimates or notes you saved on each server — not invoiced amounts. Dply bills its platform fee separately from your cloud provider.'),
        ];
    }

    /**
     * @return Collection<int, Server>
     */
    private function infrastructureServers(Organization $organization): Collection
    {
        $minAge = max(0, (int) config('subscription.standard.min_billable_age_days', 1));

        return $organization->servers()
            ->with('providerCredential')
            ->where('status', Server::STATUS_READY)
            ->where('created_at', '<=', now()->subDays($minAge))
            ->orderBy('name')
            ->get()
            ->reject(fn (Server $server): bool => $server->isManagedProductHost())
            ->values();
    }

    /**
     * Provider infrastructure estimate for a single BYO server.
     *
     * @return array{
     *   id: string,
     *   name: string,
     *   provider: ?string,
     *   plan: ?string,
     *   monthly_usd_cents: int,
     *   source: string,
     *   detail: ?string,
     * }
     */
    /** @return array<string, mixed> */
    public function providerEstimateForServer(Server $server): array
    {
        $server->loadMissing('providerCredential');

        return $this->serverRow($server);
    }

    /**
     * @return array{
     *   id: string,
     *   name: string,
     *   provider: ?string,
     *   plan: ?string,
     *   monthly_usd_cents: int,
     *   source: string,
     *   detail: ?string,
     * }
     */
    private function serverRow(Server $server): array
    {
        $meta = is_array($server->meta) ? $server->meta : [];
        $note = isset($meta['cost_monthly_note']) ? (string) $meta['cost_monthly_note'] : null;

        $parsed = $this->noteParser->parse($note);
        if ($parsed !== null) {
            return [
                'id' => (string) $server->id,
                'name' => (string) $server->name,
                'provider' => $server->provider->label(),
                'plan' => (string) ($server->size ?: ''),
                'monthly_usd_cents' => $this->noteParser->toUsdCents($parsed['amount'], $parsed['currency']),
                'source' => 'note',
                'detail' => $note,
            ];
        }

        if (ServerProviderCostEstimator::isSupported($server->provider)) {
            try {
                $estimate = $this->providerCostEstimator->estimate($server);
                $currency = (string) ($estimate['currency'] ?? 'USD');

                return [
                    'id' => (string) $server->id,
                    'name' => (string) $server->name,
                    'provider' => (string) ($estimate['provider_label'] ?? $server->provider->label()),
                    'plan' => (string) ($estimate['plan'] ?? $server->size),
                    'monthly_usd_cents' => $this->noteParser->toUsdCents((float) $estimate['monthly'], $currency),
                    'source' => 'catalog',
                    'detail' => (string) ($estimate['source'] ?? __('Provider catalog')),
                ];
            } catch (ProviderCostUnavailableException $e) {
                return $this->unknownRow($server, $e->getMessage());
            } catch (Throwable) {
                return $this->unknownRow($server, __('Provider pricing lookup failed'));
            }
        }

        return $this->unknownRow($server, null);
    }

    /**
     * @return array{
     *   id: string,
     *   name: string,
     *   provider: ?string,
     *   plan: ?string,
     *   monthly_usd_cents: int,
     *   source: string,
     *   detail: ?string,
     * }
     */
    private function unknownRow(Server $server, ?string $reason): array
    {
        return [
            'id' => (string) $server->id,
            'name' => (string) $server->name,
            'provider' => $server->provider->label(),
            'plan' => (string) ($server->size ?: ''),
            'monthly_usd_cents' => 0,
            'source' => 'unknown',
            'detail' => $reason,
        ];
    }
}
