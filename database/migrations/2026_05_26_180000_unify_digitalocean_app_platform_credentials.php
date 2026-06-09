<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Collapse 'digitalocean_app_platform' provider credentials into the
     * canonical 'digitalocean' row. A DigitalOcean PAT authorizes Droplets,
     * Domains, and App Platform with the same scopes, so a separate row
     * for App Platform was always a UX artifact, not a real distinction.
     *
     * Per-org rule: if the org already has a 'digitalocean' row, drop the
     * App Platform row (same vendor, same token in practice). Otherwise
     * relabel the App Platform row as 'digitalocean'.
     */
    public function up(): void
    {
        $appPlatformRows = DB::table('provider_credentials')
            ->where('provider', 'digitalocean_app_platform')
            ->get(['id', 'organization_id']);

        foreach ($appPlatformRows as $row) {
            $hasDigitalOcean = DB::table('provider_credentials')
                ->where('organization_id', $row->organization_id)
                ->where('provider', 'digitalocean')
                ->exists();

            if ($hasDigitalOcean) {
                DB::table('provider_credentials')->where('id', $row->id)->delete();
            } else {
                DB::table('provider_credentials')
                    ->where('id', $row->id)
                    ->update(['provider' => 'digitalocean']);
            }
        }
    }

    public function down(): void
    {
        // Not reversible: we cannot tell which 'digitalocean' rows were
        // originally App Platform rows. The unification is intentional.
    }
};
