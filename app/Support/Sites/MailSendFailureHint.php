<?php

declare(strict_types=1);

namespace App\Support\Sites;

/**
 * What to actually do about a failed test send.
 *
 * The transport error is shown verbatim above this hint, and providers word
 * theirs for their own API rather than for the person reading a deploy console:
 * Cloudflare answers `email.sending.error.email.invalid`, which says neither
 * which address was rejected nor why.
 *
 * The rule is one hint per recognised cause and NOTHING otherwise. This line
 * used to always read "if this names a missing class/package, add the provider's
 * transport package" — advice that was wrong for every failure that wasn't a
 * missing package, and wrong advice on a diagnostic screen is worse than none:
 * it sends someone to edit composer.json over a rejected From address.
 */
final class MailSendFailureHint
{
    public static function for(string $provider, string $error): ?string
    {
        $provider = strtolower(trim($provider));
        $haystack = strtolower($error);

        $has = static fn (string ...$needles): bool => array_filter(
            $needles,
            static fn (string $n): bool => str_contains($haystack, $n),
        ) !== [];

        // A missing transport bridge — the one case where composer is the fix.
        if ($has('not found') && str_contains($haystack, 'class')) {
            $packages = MailTransportRequirements::packagesFor($provider);

            if ($packages !== []) {
                return sprintf(
                    '%s sends through %s, which is not installed in this app. Add it to the repository and redeploy:  composer require %s',
                    $provider,
                    implode(' + ', $packages),
                    implode(' ', $packages),
                );
            }

            return 'This looks like a missing package. Add the provider\'s transport package to your app\'s composer.json and redeploy.';
        }

        // Cloudflare rejects both an unauthorised sender and an unroutable
        // destination with the same opaque code, so name both.
        if ($provider === 'cloudflare' && $has('email.invalid', 'email.sending.error')) {
            return 'Cloudflare rejected an address. The From address must be on a domain enabled for sending in that Cloudflare account, '
                .'and if the destination is behind Email Routing it has to be a verified destination address. Check MAIL_FROM_ADDRESS first.';
        }

        if ($has('unauthorized', 'forbidden', '401', '403', 'invalid api key', 'authentication failed', 'invalid credentials')) {
            return 'The provider rejected the credentials. Re-check this binding\'s API key or token, then redeploy so the new value reaches the server.';
        }

        if ($has('not verified', 'unverified', 'domain not found', 'sender identity', 'not authorized to send')) {
            return 'The provider does not consider this sender verified. Verify the sending domain (and its DNS records) in the provider\'s dashboard, then try again.';
        }

        if ($has('rate limit', 'too many requests', '429')) {
            return 'The provider is rate-limiting this account. Wait and try again — nothing about this site\'s configuration is wrong.';
        }

        // Unrecognised: the verbatim transport error is already on screen and is
        // more useful than a guess.
        return null;
    }
}
