<?php

declare(strict_types=1);

namespace App\Modules\Providers\Cloudflare;

use App\Models\ProviderCredential;
use App\Models\Site;
use App\Support\Mail\Guided\GuidedMailGate;
use App\Support\Mail\Guided\GuidedMailProvider;
use App\Support\Mail\Guided\GuidedMailRecordStatus;
use App\Support\Mail\Guided\GuidedMailStep;
use App\Support\Mail\Guided\GuidedMailVerifyResult;
use App\Support\Mail\MailPlaceholderResolver;

/**
 * Guided + verified email setup on a customer's own domain through their own
 * Cloudflare account. dply holds a DNS-edit token (control plane only) to verify
 * the zone; a separate Email-Sending token — supplied by the user — is what
 * actually sends and is what ships to the deployed app's .env.
 *
 * The domain-onboarding step itself is dashboard-only on Cloudflare's side (no
 * REST endpoint), so {@see onboardingSteps()} is non-empty: dply guides, then
 * verifies with a real send.
 */
class CloudflareGuidedMailProvider implements GuidedMailProvider
{
    public function key(): string
    {
        return 'cloudflare';
    }

    public function gate(Site $site): GuidedMailGate
    {
        if ($this->dnsCredentialFor($site) === null) {
            return GuidedMailGate::ineligible(
                'Connect a Cloudflare DNS credential for this domain first — guided email setup verifies your records through it.'
            );
        }

        $domains = $site->domains
            ->pluck('hostname')
            ->map(static fn ($h): string => strtolower(trim((string) $h)))
            ->filter(static fn (string $h): bool => $h !== '')
            ->unique()
            ->values()
            ->all();

        if ($domains === []) {
            return GuidedMailGate::ineligible('Add a domain to this site before setting up email.');
        }

        return new GuidedMailGate(true, null, $domains);
    }

    public function onboardingSteps(string $domain): array
    {
        return [
            new GuidedMailStep(
                'Enable Email Sending in Cloudflare',
                "In the Cloudflare dashboard, open your account → Email → Email Service, and enable Email Sending. This step is dashboard-only — Cloudflare doesn't expose it over the API yet."
            ),
            new GuidedMailStep(
                'Onboard the domain '.$domain.' lives in',
                "Add the Cloudflare zone that holds {$domain} as a sending domain. If {$domain} is a subdomain, onboard its parent zone — the dashboard won't take a subdomain directly. Because the zone is on Cloudflare DNS, Cloudflare creates the SPF, DKIM, and DMARC records for you."
            ),
            new GuidedMailStep(
                'Subdomain? Enable sending on it',
                "For a subdomain, run Check DNS records below — dply looks for a sending entry for {$domain} under its zone and offers to add it through your DNS token. Skip this if {$domain} is the zone itself."
            ),
            new GuidedMailStep(
                'Create an Email Sending token',
                'Create an API token scoped to “Email Sending: Edit” (only that permission). Paste it below as the sending key — keep it separate from your DNS token.'
            ),
            new GuidedMailStep(
                'Verify',
                'Once the dashboard shows the domain as verified, run Verify below — dply sends a real test message through Cloudflare to confirm the whole chain works.'
            ),
        ];
    }

    public function pollRecords(Site $site, string $domain): GuidedMailRecordStatus
    {
        $domain = strtolower(trim($domain));
        $credential = $this->dnsCredentialFor($site);
        if ($credential === null) {
            return GuidedMailRecordStatus::unreadable('No Cloudflare DNS credential is connected for this site.');
        }

        try {
            $dns = new CloudflareDnsService($credential);
            $zone = $this->resolveZone($dns, $domain);
            if ($zone === null) {
                return GuidedMailRecordStatus::unreadable(
                    "Couldn't find {$domain} as a zone in the connected Cloudflare account."
                );
            }

            if ($zone === $domain) {
                return $this->recordStatus($dns, $zone, $domain);
            }

            // A subdomain sends only through an entry under its zone. When the
            // token can't read those entries, fall back to the documented layout.
            try {
                $entry = $this->sendingSubdomainEntry($dns->listEmailSendingSubdomains($zone), $zone, $domain);
            } catch (\Throwable) {
                return $this->recordStatus($dns, $zone, $domain)->forSubdomain($zone, null);
            }

            if ($entry === null) {
                return $this->recordStatus($dns, $zone, $domain)->forSubdomain($zone, false);
            }

            $expected = is_string($entry['tag'] ?? null) && $entry['tag'] !== ''
                ? $dns->emailSendingSubdomainDnsRecords($zone, $entry['tag'])
                : [];

            return $this->expectedRecordStatus($dns, $zone, $domain, $expected)->forSubdomain($zone, true);
        } catch (\Throwable $e) {
            return GuidedMailRecordStatus::unreadable($e->getMessage());
        }
    }

    /**
     * Add the subdomain as an Email Sending entry under its zone, through the
     * connected DNS credential. Returns null on success, or a message to show.
     */
    public function enableSendingSubdomain(Site $site, string $domain): ?string
    {
        $domain = strtolower(trim($domain));
        $credential = $this->dnsCredentialFor($site);
        if ($credential === null) {
            return 'No Cloudflare DNS credential is connected for this site.';
        }

        try {
            $dns = new CloudflareDnsService($credential);
            $zone = $this->resolveZone($dns, $domain);
            if ($zone === null) {
                return "Couldn't find {$domain} as a zone in the connected Cloudflare account.";
            }
            if ($zone === $domain) {
                return "{$domain} is a zone, not a subdomain — onboard it under Email → Email Sending in the Cloudflare dashboard.";
            }

            $dns->createEmailSendingSubdomain($zone, $domain);

            return null;
        } catch (\Throwable $e) {
            return $e->getMessage().' Make sure Email Sending is enabled on '.($zone ?? 'the parent zone').' first.';
        }
    }

    public function verify(Site $site, string $domain, array $credentials, string $recipient): GuidedMailVerifyResult
    {
        $accountId = trim((string) ($credentials['account_id'] ?? ''));
        $sendingToken = trim((string) ($credentials['key'] ?? ''));
        if ($accountId === '' || $sendingToken === '') {
            return GuidedMailVerifyResult::fail('Enter your Cloudflare account ID and an Email Sending token first.');
        }

        $domain = strtolower(trim($domain));
        $from = MailPlaceholderResolver::resolve($site, trim((string) ($credentials['from_address'] ?? '')));
        if ($from === '') {
            $from = 'hello@'.$domain;
        }

        // Cloudflare only sends from an onboarded domain and answers anything else
        // with an opaque `email.invalid` — catch the mismatch here, by name.
        if (filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            return GuidedMailVerifyResult::fail(
                "The From address \"{$from}\" isn't a valid email address. Set it to an address on {$domain}, like hello@{$domain}."
            );
        }
        // A parent or child of the chosen domain can be legitimately onboarded
        // (zone + sending subdomain), so only an unrelated domain is refused.
        $fromDomain = strtolower(substr($from, strrpos($from, '@') + 1));
        $related = $fromDomain === $domain
            || str_ends_with($fromDomain, '.'.$domain)
            || str_ends_with($domain, '.'.$fromDomain);
        if (! $related) {
            return GuidedMailVerifyResult::fail(
                "The From address {$from} isn't on {$domain}. Cloudflare only sends from a domain onboarded for sending — "
                ."change the From address to something like hello@{$domain}, or pick {$fromDomain} as the sending domain if that's the one set up in Cloudflare."
            );
        }

        // Resolve ${APP_NAME}-style placeholders so Cloudflare registers a real
        // sender name rather than a literal "${APP_NAME}" — this is the control
        // plane, not the deployed app, so phpdotenv isn't in play.
        $fromName = MailPlaceholderResolver::resolve($site, trim((string) ($credentials['from_name'] ?? '')));

        try {
            $email = new CloudflareEmailService($sendingToken);
        } catch (\InvalidArgumentException $e) {
            return GuidedMailVerifyResult::fail($e->getMessage());
        }

        $error = $email->send(
            $accountId,
            $fromName !== '' ? ['address' => $from, 'name' => $fromName] : ['address' => $from],
            $recipient,
            'dply email verification',
            '<p>This is a verification message sent through Cloudflare Email Sending by dply. '
                .'If you received it, sending from <strong>'.e($domain).'</strong> is working.</p>',
            'This is a verification message sent through Cloudflare Email Sending by dply. '
                .'If you received it, sending from '.$domain.' is working.',
        );

        return $error === null
            ? GuidedMailVerifyResult::pass()
            : GuidedMailVerifyResult::fail($this->explainSendError($error, $from, $recipient, $domain));
    }

    /**
     * Turn Cloudflare's dotted error codes into something actionable, keeping the
     * raw code at the end for support. Unrecognised errors pass through verbatim.
     */
    private function explainSendError(string $error, string $from, string $recipient, string $domain): string
    {
        $code = strtolower($error);

        if (str_contains($code, 'sender_not_configured')) {
            return "Cloudflare has no sending domain for {$from} in this account. Add {$domain} under Email → Email Sending in the same Cloudflare account this token belongs to. ({$error})";
        }

        if (str_contains($code, 'email.invalid')) {
            return "Cloudflare rejected sending from {$from} to {$recipient}. Check that {$domain} itself shows as verified under Email → Email Sending "
                .'in the account this token belongs to (for a subdomain: its parent zone is onboarded and Check DNS records shows sending enabled on it), '
                ."and that {$recipient} is a real, deliverable address. ({$error})";
        }

        return $error;
    }

    /**
     * Where Cloudflare Email Sending puts its records: SPF + DKIM on the
     * `cf-bounce` subdomain of the sending domain, DMARC at `_dmarc`. A DMARC
     * policy on the zone apex also covers a subdomain (DMARC falls back to the
     * organizational domain), so that counts too.
     */
    public function recordStatus(CloudflareDnsService $dns, string $zone, string $domain): GuidedMailRecordStatus
    {
        $bounce = 'cf-bounce.'.$domain;

        $spf = $this->anyTxtMatches($dns->listDnsRecords($zone, 'TXT', $bounce), 'v=spf1')
            || $this->anyTxtMatches($dns->listDnsRecords($zone, 'TXT', $domain), 'v=spf1');

        $dkim = $this->anyRecordNameEndsWith($dns->listDnsRecords($zone, 'TXT'), '._domainkey.'.$domain);

        $dmarc = $this->anyTxtMatches($dns->listDnsRecords($zone, 'TXT', '_dmarc.'.$domain), 'v=dmarc1')
            || ($zone !== $domain && $this->anyTxtMatches($dns->listDnsRecords($zone, 'TXT', '_dmarc.'.$zone), 'v=dmarc1'));

        return new GuidedMailRecordStatus($spf, $dkim, $dmarc);
    }

    /**
     * The zone's sending entry that covers $domain: an exact match, or a
     * leftmost wildcard (`*.example.com`) over it. Disabled entries don't count.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return array<string, mixed>|null
     */
    private function sendingSubdomainEntry(array $entries, string $zone, string $domain): ?array
    {
        foreach ($entries as $entry) {
            $name = strtolower((string) ($entry['name'] ?? ''));
            if (($entry['enabled'] ?? true) === false) {
                continue;
            }
            if ($name === $domain || (str_starts_with($name, '*.') && str_ends_with($domain, substr($name, 1)))) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Check the records Cloudflare says the subdomain needs, sorted into
     * SPF / DKIM / DMARC. Any category Cloudflare didn't list falls back to the
     * documented layout from {@see recordStatus()}.
     *
     * @param  list<array<string, mixed>>  $expected
     */
    private function expectedRecordStatus(CloudflareDnsService $dns, string $zone, string $domain, array $expected): GuidedMailRecordStatus
    {
        $found = ['spf' => null, 'dkim' => null, 'dmarc' => null];

        foreach ($expected as $record) {
            $type = strtoupper((string) ($record['type'] ?? ''));
            $name = strtolower(trim((string) ($record['name'] ?? '')));
            $content = strtolower((string) ($record['content'] ?? ''));
            $name = match (true) {
                $name === '' || $name === '@' => $zone,
                $name === $zone || str_ends_with($name, '.'.$zone) => $name,
                default => $name.'.'.$zone,
            };

            $category = match (true) {
                $type === 'TXT' && str_starts_with($name, '_dmarc.') => 'dmarc',
                str_contains($name, '._domainkey.') => 'dkim',
                $type === 'TXT' && str_contains($content, 'v=spf1') => 'spf',
                default => null,
            };
            if ($category === null) {
                continue;
            }

            $needle = $category === 'dkim' ? '' : ($category === 'spf' ? 'v=spf1' : 'v=dmarc1');
            $present = $needle === ''
                ? $dns->listDnsRecords($zone, $type, $name) !== []
                : $this->anyTxtMatches($dns->listDnsRecords($zone, $type, $name), $needle);

            $found[$category] = ($found[$category] ?? true) && $present;
        }

        $fallback = $found['spf'] === null || $found['dkim'] === null ? $this->recordStatus($dns, $zone, $domain) : null;

        // DMARC on the zone apex covers the subdomain too.
        $dmarc = ($found['dmarc'] ?? false)
            || $this->anyTxtMatches($dns->listDnsRecords($zone, 'TXT', '_dmarc.'.$domain), 'v=dmarc1')
            || $this->anyTxtMatches($dns->listDnsRecords($zone, 'TXT', '_dmarc.'.$zone), 'v=dmarc1');

        return new GuidedMailRecordStatus(
            $found['spf'] ?? $fallback->spf,
            $found['dkim'] ?? $fallback->dkim,
            $dmarc,
        );
    }

    /**
     * The Cloudflare DNS credential dply will verify through: the site's pinned
     * DNS credential when it's Cloudflare, else the org's first Cloudflare one.
     */
    private function dnsCredentialFor(Site $site): ?ProviderCredential
    {
        $pinned = $site->dnsProviderCredential;
        if ($pinned instanceof ProviderCredential && $pinned->provider === 'cloudflare') {
            return $pinned;
        }

        return ProviderCredential::query()
            ->where('organization_id', $site->organization_id)
            ->where('provider', 'cloudflare')
            ->orderBy('created_at')
            ->first();
    }

    /** Walk subdomain labels off $domain until a Cloudflare zone matches. */
    private function resolveZone(CloudflareDnsService $dns, string $domain): ?string
    {
        $labels = explode('.', $domain);
        while (count($labels) >= 2) {
            $candidate = implode('.', $labels);
            if ($dns->findZoneId($candidate) !== null) {
                return $candidate;
            }
            array_shift($labels);
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $records
     */
    private function anyTxtMatches(array $records, string $needle): bool
    {
        foreach ($records as $record) {
            $content = strtolower((string) ($record['content'] ?? ''));
            if (str_contains($content, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $records
     */
    private function anyRecordNameEndsWith(array $records, string $suffix): bool
    {
        foreach ($records as $record) {
            $name = strtolower((string) ($record['name'] ?? ''));
            if (str_ends_with($name, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
