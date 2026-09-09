<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\Site;
use App\Models\SiteBinding;

/**
 * The Composer packages a mail provider needs in the APP's vendor/.
 *
 * dply injects a binding's MAIL_* env at deploy, which is the half it owns. The
 * other half lives in the customer's composer.json: Laravel ships only the SMTP
 * and log transports, and every API provider is a separate bridge package. When
 * that package is absent the injected config is perfectly valid and the first
 * send dies with `Class "Symfony\Component\HttpClient\HttpClient" not found` —
 * an error that names a Symfony internal and not the thing to install.
 *
 * dply cannot fix this for them: the package belongs in a repository dply does
 * not commit to. So the contract is warn precisely, at deploy, with the exact
 * `composer require` line — not discover it from a failed send in production.
 */
final class MailTransportRequirements
{
    /**
     * Provider slug => packages that must be installed for it to send.
     *
     * smtp and log are absent on purpose: both are in laravel/framework
     * already, so there is nothing to require.
     *
     * @var array<string, list<string>>
     */
    private const PACKAGES = [
        'mailgun' => ['symfony/mailgun-mailer', 'symfony/http-client'],
        'postmark' => ['symfony/postmark-mailer', 'symfony/http-client'],
        'sendgrid' => ['symfony/sendgrid-mailer', 'symfony/http-client'],
        'resend' => ['resend/resend-php', 'symfony/http-client'],
        'ses' => ['aws/aws-sdk-php'],
        // No Symfony DSN scheme — the transport is Laravel-native and talks to
        // Cloudflare's HTTP API, so it needs the HTTP client and nothing else.
        'cloudflare' => ['symfony/http-client'],
    ];

    /**
     * Every provider a site's mail bindings actually use, including each leg of
     * a failover/roundrobin chain — a chain fails on whichever leg is missing
     * its package, not just the first.
     *
     * @return list<string>
     */
    public static function providersFor(Site $site): array
    {
        $providers = [];

        foreach ($site->bindings as $binding) {
            if (! $binding instanceof SiteBinding || $binding->type !== 'mail') {
                continue;
            }

            $config = is_array($binding->config) ? $binding->config : [];
            $providers[] = strtolower(trim((string) ($config['provider'] ?? '')));

            foreach ((array) ($config['legs'] ?? []) as $leg) {
                if (is_array($leg)) {
                    $providers[] = strtolower(trim((string) ($leg['provider'] ?? '')));
                }
            }
        }

        return array_values(array_unique(array_filter(
            $providers,
            static fn (string $p): bool => $p !== '' && array_key_exists($p, self::PACKAGES),
        )));
    }

    /**
     * Packages the site's mail providers need but the app does not have.
     *
     * @param  list<string>  $installed  Package names from the app's composer.lock.
     * @return array<string, list<string>> provider => missing packages
     */
    public static function missingFor(Site $site, array $installed): array
    {
        $installed = array_map(static fn (string $n): string => strtolower(trim($n)), $installed);
        $missing = [];

        foreach (self::providersFor($site) as $provider) {
            $absent = array_values(array_filter(
                self::PACKAGES[$provider],
                static fn (string $pkg): bool => ! in_array(strtolower($pkg), $installed, true),
            ));

            if ($absent !== []) {
                $missing[$provider] = $absent;
            }
        }

        return $missing;
    }

    /** The packages one provider needs, for messaging a single failed send. */
    /** @return list<string> */
    public static function packagesFor(string $provider): array
    {
        return self::PACKAGES[strtolower(trim($provider))] ?? [];
    }
}
