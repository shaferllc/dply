<?php

declare(strict_types=1);

namespace App\Modules\Deploy\Services\Concerns;

use App\Models\LogDrainCredential;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Services\Logging\LoggingSpec;
use App\Services\Logging\LoggingSpecValidator;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Attach the `logging` binding type (log drains + the v2 logging spec) and
 * build the env it injects at deploy.
 */
trait ManagesLoggingBindings
{
    /**
     * @param  array<string, mixed> $params
     */
    private function attachLogging(Site $site, array $params): SiteBinding
    {
        $validProviders = ['papertrail', 'logtail', 'syslog', 'dply_realtime'];
        $provider = strtolower(trim((string) ($params['provider'] ?? '')));
        if (! in_array($provider, $validProviders, true)) {
            throw new InvalidArgumentException(__('Unsupported log drain provider.'));
        }

        $creds = $this->resolveLogDrainCredentials($site, $provider, $params);
        $this->validateLogDrainCredentials($provider, $creds);

        // Build the v2 logging spec (the structure dply will own and generate
        // into config/logging.php from Phase 2 on) behaviour-preservingly from
        // the single-provider form. We store it on `config` now so the data is
        // ready ahead of the generator/overlay; `injected_env` stays the legacy
        // drain env so nothing about today's behaviour changes yet. The
        // transitional `provider` key keeps the current modal's form binding
        // working until the Phase 3 editor replaces it.
        $spec = LoggingSpec::fromLegacyProvider($provider, $creds);
        (new LoggingSpecValidator)->validate($spec);

        $binding = $this->persist($site, 'logging', [
            'mode' => 'attach_existing',
            'status' => SiteBinding::STATUS_CONFIGURED,
            'name' => $this->logDrainLabel($provider, $creds),
            'target_type' => 'log_drain',
            'target_id' => null,
            'injected_env' => $this->logDrainEnv($provider, $creds),
            'config' => ['provider' => $provider] + $spec,
            'last_error' => null,
        ]);

        $this->maybeSaveLogDrainCredential($site, $provider, $params, $creds);

        return $binding;
    }

    /**
     * Persist a full v2 logging spec from the Phase 3 editor. The spec (the
     * secret-free structure dply generates into config/logging.php) is stored on
     * `config`; the secret leaf values are written to `injected_env` keyed by
     * each channel's env map, so the generated file's `env(...)` references
     * resolve. Secrets the operator left blank on an edit are preserved from the
     * existing binding rather than wiped.
     *
     * @param  array<string, mixed> $spec
     * @param  array<string, array<string, string>>  $secrets  [channelName][field] => value
     */
    public function saveLoggingSpec(Site $site, array $spec, array $secrets = []): SiteBinding
    {
        $spec['version'] = LoggingSpec::VERSION;
        (new LoggingSpecValidator)->validate($spec);

        $existing = $site->bindings->firstWhere('type', 'logging');
        $existingEnv = ($existing instanceof SiteBinding && is_array($existing->injected_env)) ? $existing->injected_env : [];

        return $this->persist($site, 'logging', [
            'mode' => 'attach_existing',
            'status' => SiteBinding::STATUS_CONFIGURED,
            'name' => $this->loggingSpecLabel($spec),
            'target_type' => 'log_drain',
            'target_id' => null,
            'injected_env' => $this->loggingInjectedEnvFromSpec($spec, $secrets, $existingEnv, $site),
            'config' => $spec,
            'last_error' => null,
        ]);
    }

    /**
     * The site's stable dply Realtime routing token, minted on first use. The
     * generated SyslogUdpHandler stamps it as the syslog ident and the drain
     * receiver maps datagrams back to the site by it.
     */
    private function ensureLogDrainToken(Site $site): string
    {
        $token = trim((string) ($site->log_drain_token ?? ''));
        if ($token === '') {
            $token = 'dly_'.Str::lower(Str::random(40));
            $site->forceFill(['log_drain_token' => $token])->save();
        }

        return $token;
    }

    /**
     * Build the secret env map a spec injects. Each channel's `env` map names
     * the env key per secret field; the value comes from $secrets, falling back
     * to the previously-stored value when the operator didn't re-enter it.
     * dply Realtime is special: its endpoint comes from config, not the form.
     *
     * @param  array<string, mixed> $spec
     * @param  array<string, array<string, string>>  $secrets
     * @param  array<string, mixed> $existingEnv
     * @return array<string, string>
     */
    private function loggingInjectedEnvFromSpec(array $spec, array $secrets, array $existingEnv, Site $site): array
    {
        $env = [];
        foreach ((array) ($spec['channels'] ?? []) as $channel) {
            if (! is_array($channel)) {
                continue;
            }
            $name = (string) ($channel['name'] ?? '');
            $type = (string) ($channel['type'] ?? '');
            $envMap = is_array($channel['env'] ?? null) ? $channel['env'] : [];

            if ($type === 'dply_realtime') {
                // Endpoint comes from config; the routing token from the site.
                foreach (['host' => 'host', 'port' => 'port'] as $field => $cfgKey) {
                    if (isset($envMap[$field])) {
                        $env[$envMap[$field]] = (string) config('log_drains.dply_realtime.'.$cfgKey, '');
                    }
                }
                if (isset($envMap['token'])) {
                    $env[$envMap['token']] = $this->ensureLogDrainToken($site);
                }

                continue;
            }

            foreach ($envMap as $field => $key) {
                $key = (string) $key;
                $new = trim((string) ($secrets[$name][$field] ?? ''));
                $value = $new !== '' ? $new : (string) ($existingEnv[$key] ?? '');
                if ($value !== '') {
                    $env[$key] = $value;
                }
            }
        }

        return $env;
    }

    /** @param  array<string, mixed> $spec */
    private function loggingSpecLabel(array $spec): string
    {
        $channels = (array) ($spec['channels'] ?? []);
        $count = count($channels);
        $default = (string) ($spec['default'] ?? '');

        return $count === 1
            ? __('Logging · :default', ['default' => $default])
            : __('Logging · :count channels (default :default)', ['count' => $count, 'default' => $default]);
    }

    /**
     * Resolve drain credentials: from a saved LogDrainCredential when
     * $params['credential_id'] is set, otherwise from the typed form fields.
     *
     * @param  array<string, mixed> $params
     * @return array<string, string>
     */
    private function resolveLogDrainCredentials(Site $site, string $provider, array $params): array
    {
        $credentialId = trim((string) ($params['credential_id'] ?? ''));
        if ($credentialId !== '') {
            $cred = LogDrainCredential::query()
                ->where('organization_id', $site->organization_id)
                ->where('provider', $provider)
                ->whereKey($credentialId)
                ->first();

            if (! $cred instanceof LogDrainCredential) {
                throw new InvalidArgumentException(__('That saved log drain credential is no longer available.'));
            }

            return ($cred->credentials );
        }

        return match ($provider) {
            'papertrail' => [
                'host' => trim((string) ($params['host'] ?? 'logs.papertrailapp.com')),
                'port' => trim((string) ($params['port'] ?? '')),
            ],
            'logtail' => [
                'source_token' => trim((string) ($params['source_token'] ?? '')),
            ],
            'syslog' => [
                'host' => trim((string) ($params['host'] ?? '')),
                'port' => trim((string) ($params['port'] ?? '514')),
            ],
            default => [],
        };
    }

    /** @param  array<string, mixed> $creds */
    private function validateLogDrainCredentials(string $provider, array $creds): void
    {
        match ($provider) {
            'papertrail' => ($creds['port'] ?? '') === ''
                ? throw new InvalidArgumentException(__('Papertrail port is required.'))
                : null,
            'logtail' => ($creds['source_token'] ?? '') === ''
                ? throw new InvalidArgumentException(__('Logtail source token is required.'))
                : null,
            'syslog' => ($creds['host'] ?? '') === ''
                ? throw new InvalidArgumentException(__('Syslog host is required.'))
                : null,
            default => null,
        };
    }

    /**
     * Build the env vars the logging binding injects at deploy.
     *
     * @param  array<string, mixed> $creds
     * @return array<string, string>
     */
    private function logDrainEnv(string $provider, array $creds): array
    {
        return match ($provider) {
            'papertrail' => array_filter([
                'LOG_CHANNEL' => 'papertrail',
                'PAPERTRAIL_URL' => ($creds['host'] ?? '') ?: null,
                'PAPERTRAIL_PORT' => ($creds['port'] ?? '') ?: null,
            ], fn ($v) => $v !== null),
            'logtail' => array_filter([
                'LOG_CHANNEL' => 'stack',
                'LOG_STACK' => 'single,logtail',
                'LOGTAIL_SOURCE_TOKEN' => ($creds['source_token'] ?? '') ?: null,
            ], fn ($v) => $v !== null),
            'syslog' => ['LOG_CHANNEL' => 'syslog'],
            'dply_realtime' => array_filter([
                'LOG_CHANNEL' => 'papertrail',
                // PAPERTRAIL_* drives the stock channel on env-only hosts;
                // DPLY_LOG_DRAIN_* are the dedicated keys the generated overlay
                // file references (so dply Realtime never collides with a real
                // Papertrail channel). Emitting both is harmless.
                'PAPERTRAIL_URL' => (string) config('log_drains.dply_realtime.host', ''),
                'PAPERTRAIL_PORT' => (string) config('log_drains.dply_realtime.port', ''),
                'DPLY_LOG_DRAIN_HOST' => (string) config('log_drains.dply_realtime.host', ''),
                'DPLY_LOG_DRAIN_PORT' => (string) config('log_drains.dply_realtime.port', ''),
            ], fn ($v) => $v !== ''),
            default => [],
        };
    }

    /** @param  array<string, mixed> $creds */
    private function logDrainLabel(string $provider, array $creds): string
    {
        return match ($provider) {
            'papertrail' => 'Papertrail'.($creds['port'] !== '' ? ' :'.$creds['port'] : ''),
            'logtail' => 'Logtail',
            'syslog' => 'Syslog'.($creds['host'] !== '' ? ' '.$creds['host'] : ''),
            'dply_realtime' => 'dply Realtime',
            default => $provider,
        };
    }

    /**
     * Persist typed credentials as a reusable LogDrainCredential when the
     * operator ticked "save for reuse". No-op when reusing a saved credential
     * or when the provider supplies no user credentials (dply_realtime).
     *
     * @param  array<string, mixed> $params
     * @param  array<string, mixed> $creds
     */
    private function maybeSaveLogDrainCredential(Site $site, string $provider, array $params, array $creds): void
    {
        if (! (bool) ($params['save_credential'] ?? false)) {
            return;
        }
        if (trim((string) ($params['credential_id'] ?? '')) !== '') {
            return;
        }
        if ($provider === 'dply_realtime' || $creds === []) {
            return;
        }

        $name = trim((string) ($params['credential_name'] ?? ''));
        if ($name === '') {
            $labels = ['papertrail' => 'Papertrail', 'logtail' => 'Logtail', 'syslog' => 'Syslog'];
            $name = ($labels[$provider] ?? ucfirst($provider)).' drain';
        }

        LogDrainCredential::query()->create([
            'organization_id' => $site->organization_id,
            'created_by_user_id' => auth()->id(),
            'provider' => $provider,
            'name' => Str::limit($name, 120, ''),
            'credentials' => $creds,
        ]);
    }
}
