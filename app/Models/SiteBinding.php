<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 *                      A persisted resource binding for a site: a managed attachment (database,
 *                      redis, queue, object storage, scheduler, workers, publication) that
 *                      contributes connection variables to the deploy environment.
 *                      The connection vars live in {@see $injected_env} (encrypted) and are merged
 *                      into the deployment environment at deploy time only — they are intentionally
 *                      kept out of the editable Variables list so the binding stays the source of
 *                      truth for them.
 * @property array<string, mixed> $config
 * @property array<string, mixed> $injected_env
 * @property string $last_error
 * @property string $mode
 * @property string $name
 * @property ?string $site_id
 * @property string $status
 * @property ?string $target_id
 * @property string $target_type
 * @property string $type
 * @property-read ?Site $site
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class SiteBinding extends Model
{
    use HasUlids;

    public const TYPES = [
        'database',
        'scheduler',
        'workers',
        'publication',
        'redis',
        'queue',
        'storage',
        'cache',
        'session',
        'logging',
        'mail',
        'broadcasting',
        'error_tracking',
        'ai',
        'captcha',
        'sms',
        'search',
        'payments',
        'oauth',
    ];

    /**
     * Types a site can hold MORE THAN ONE of — each a distinct instance keyed
     * by `name`, injecting its own (namespaced) env so they don't collide. The
     * primary instance keeps the framework's bare keys (DB_HOST, FILESYSTEM_DISK
     * …); additional named instances inject a prefixed set (DB_<NAME>_*,
     * AWS_<DISK>_* …) plus a config snippet to register the named connection.
     * Every other type collapses to one row per site via the (site_id, type)
     * natural key. Grows as each type's env-namespacing is wired in.
     */
    public const MULTI_INSTANCE_TYPES = [
        'storage',
        'database',
        'redis',
        // Provider-keyed integrations: each provider owns an independent key
        // namespace (no shared selector key), so several DIFFERENT providers
        // coexist on one site without collision — the instance IS the provider.
        // (mail/broadcasting/search/payments are excluded: they share a selector
        // key — MAIL_MAILER / BROADCAST_CONNECTION / SCOUT_DRIVER / CASHIER_* —
        // so they need real per-instance namespacing first.)
        'ai',
        'oauth',
        'sms',
        'captcha',
        // payments: Stripe (STRIPE_*/CASHIER_*) and Paddle (PADDLE_*) share no
        // env keys, so the two providers coexist; the instance is the provider.
        'payments',
        // Mail: ONE primary (default) mailer — any provider or a failover chain —
        // owns MAIL_MAILER + the bare keys. Named secondaries are SMTP/log only
        // (inline-configurable per mailer), injecting MAIL_<NAME>_* + a
        // config/mail.php snippet. API providers (Mailgun/SES/…) read global
        // config/services.php creds, so they can't be a second instance.
        'mail',
    ];

    public static function isMultiInstance(string $type): bool
    {
        return in_array($type, self::MULTI_INSTANCE_TYPES, true);
    }

    public const STATUS_CONFIGURED = 'configured';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROVISIONING = 'provisioning';

    public const STATUS_ERROR = 'error';

    protected $table = 'site_bindings';

    protected $fillable = [
        'site_id',
        'type',
        'mode',
        'status',
        'name',
        'target_type',
        'target_id',
        'injected_env',
        'config',
        'last_error',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'injected_env' => 'encrypted:array',
            'config' => 'array',
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Connection variables this binding contributes at deploy time.
     *
     * @return array<string, string>
     */
    public function connectionEnv(): array
    {
        $env = $this->injected_env;

        $clean = [];
        foreach ($env as $key => $value) {
            if ($key !== '') {
                $clean[$key] = (string) $value;
            }
        }

        return $clean;
    }
}
