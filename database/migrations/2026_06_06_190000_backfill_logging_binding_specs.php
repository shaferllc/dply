<?php

use App\Models\SiteBinding;
use App\Services\Logging\LoggingSpec;
use Illuminate\Database\Migrations\Migration;

/**
 * Managed logging now stores a full v2 channel *spec* on each logging
 * binding's `config` (the structure dply generates into config/logging.php).
 * Existing bindings carry only the original `{provider}` shape, so backfill the
 * equivalent spec behaviour-preservingly (Q8): each old provider maps to the
 * exact channel/default/stack that reproduces its current env output.
 *
 * Uses the Eloquent model so the encrypted `injected_env` cast round-trips. For
 * `dply_realtime` bindings we also add the dedicated DPLY_LOG_DRAIN_* env keys
 * the generated overlay references (the old binding only had PAPERTRAIL_*),
 * pulled from config — so a migrated dply Realtime drain keeps working once the
 * file is overlaid. User-secret channels (papertrail/logtail/syslog) keep their
 * existing injected_env untouched: their env keys are unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        SiteBinding::query()->where('type', 'logging')->cursor()->each(function (SiteBinding $binding): void {
            $config = is_array($binding->config) ? $binding->config : [];
            if (LoggingSpec::isV2($config)) {
                return;
            }

            $provider = strtolower(trim((string) ($config['provider'] ?? 'papertrail')));
            $spec = LoggingSpec::fromLegacyProvider($provider, []);
            $binding->config = ['provider' => $provider] + $spec;

            if ($provider === 'dply_realtime') {
                $env = is_array($binding->injected_env) ? $binding->injected_env : [];
                $env['DPLY_LOG_DRAIN_HOST'] = (string) config('log_drains.dply_realtime.host', '');
                $env['DPLY_LOG_DRAIN_PORT'] = (string) config('log_drains.dply_realtime.port', '');
                $binding->injected_env = array_filter($env, fn ($v) => (string) $v !== '');
            }

            $binding->save();
        });
    }

    public function down(): void
    {
        SiteBinding::query()->where('type', 'logging')->cursor()->each(function (SiteBinding $binding): void {
            $config = is_array($binding->config) ? $binding->config : [];
            if (! LoggingSpec::isV2($config)) {
                return;
            }
            $binding->config = ['provider' => (string) ($config['provider'] ?? 'papertrail')];
            $binding->save();
        });
    }
};
