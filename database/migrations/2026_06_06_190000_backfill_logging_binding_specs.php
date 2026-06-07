<?php

use App\Services\Logging\LoggingSpec;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Managed logging now stores a full v2 channel *spec* on each logging
 * binding's `config` (the structure dply generates into config/logging.php).
 * Existing bindings carry only the original `{provider}` shape, so backfill the
 * equivalent spec behaviour-preservingly (Q8): each old provider maps to the
 * exact channel/default/stack that reproduces its current env output.
 *
 * Only `config` is touched — `injected_env` (the secrets) is left alone, and
 * the spec's env keys are chosen to match the keys those secrets already live
 * under, so a migrated site's logging behaviour is unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('site_bindings')->where('type', 'logging')->get(['id', 'config']);

        foreach ($rows as $row) {
            $config = $this->decode($row->config);
            if (LoggingSpec::isV2($config)) {
                continue; // already migrated
            }

            $provider = strtolower(trim((string) ($config['provider'] ?? 'papertrail')));
            $spec = LoggingSpec::fromLegacyProvider($provider, []);

            DB::table('site_bindings')
                ->where('id', $row->id)
                ->update(['config' => json_encode(['provider' => $provider] + $spec)]);
        }
    }

    public function down(): void
    {
        // Reduce each spec back to the bare {provider} shape it came from.
        $rows = DB::table('site_bindings')->where('type', 'logging')->get(['id', 'config']);

        foreach ($rows as $row) {
            $config = $this->decode($row->config);
            if (! LoggingSpec::isV2($config)) {
                continue;
            }
            $provider = (string) ($config['provider'] ?? 'papertrail');

            DB::table('site_bindings')
                ->where('id', $row->id)
                ->update(['config' => json_encode(['provider' => $provider])]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }
};
