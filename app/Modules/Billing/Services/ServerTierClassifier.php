<?php

namespace App\Modules\Billing\Services;

use App\Enums\ServerTier;

/**
 * Classifies a server into a billing tier from its detected specs.
 *
 * Tier is the **larger** of the cpu-derived and ram-derived bucket — a box
 * with cheap CPUs but lots of RAM still pays the higher-RAM-tier price,
 * because dply prices its own work, not the customer's invoice.
 *
 * When inputs are null (e.g. metrics haven't reported yet, custom SSH box
 * still onboarding), this falls back to XS so a freshly-connected server
 * never gets accidentally billed at XL while specs are unknown.
 */
class ServerTierClassifier
{
    public function classify(?int $cpuCount, ?int $memMb): ServerTier
    {
        $cpuTier = $this->fromCpu($cpuCount);
        $memTier = $this->fromMemory($memMb);

        return $cpuTier->weight() >= $memTier->weight() ? $cpuTier : $memTier;
    }

    private function fromCpu(?int $cpuCount): ServerTier
    {
        if ($cpuCount === null || $cpuCount <= 1) {
            return ServerTier::XS;
        }
        if ($cpuCount === 2) {
            return ServerTier::S;
        }
        if ($cpuCount <= 4) {
            return ServerTier::M;
        }
        if ($cpuCount <= 8) {
            return ServerTier::L;
        }

        return ServerTier::XL;
    }

    private function fromMemory(?int $memMb): ServerTier
    {
        if ($memMb === null || $memMb <= 2048) {
            return ServerTier::XS;
        }
        if ($memMb <= 4096) {
            return ServerTier::S;
        }
        if ($memMb <= 8192) {
            return ServerTier::M;
        }
        if ($memMb <= 16384) {
            return ServerTier::L;
        }

        return ServerTier::XL;
    }
}
