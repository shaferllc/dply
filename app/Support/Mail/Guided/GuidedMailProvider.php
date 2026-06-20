<?php

declare(strict_types=1);

namespace App\Support\Mail\Guided;

use App\Models\Site;

/**
 * A mail provider that dply can *guide* a customer through setting up on their
 * own domain, then *verify* end-to-end — as opposed to a plain "paste your
 * keys" provider. Cloudflare is the first implementation; Resend/Postmark/SES
 * (whose onboarding is fully API-automatable) can drop in later as additional
 * implementations behind this same contract.
 *
 * The contract is deliberately shaped around "what does this provider need from
 * the user, and how does dply verify it" — NOT around any one provider's
 * dashboard steps — so a fully-automated provider returns an empty
 * {@see onboardingSteps()} and still satisfies the interface.
 */
interface GuidedMailProvider
{
    /** Stable provider slug, matching the mail binding provider value (e.g. `cloudflare`). */
    public function key(): string;

    /**
     * Can this site use guided setup, and which of its domains are eligible to
     * send from? Cheap: no external API calls (safe to call during render).
     */
    public function gate(Site $site): GuidedMailGate;

    /**
     * Out-of-band steps the user must complete themselves (e.g. dashboard
     * onboarding). Empty for providers whose onboarding dply can fully automate.
     *
     * @return list<GuidedMailStep>
     */
    public function onboardingSteps(string $domain): array;

    /**
     * Read DNS to confirm the provider's auth records (SPF/DKIM/DMARC) are live
     * for $domain. A pre-flight that yields good error copy; it is NOT the
     * authoritative proof — {@see verify()} is.
     */
    public function pollRecords(Site $site, string $domain): GuidedMailRecordStatus;

    /**
     * Prove the whole chain by sending a real message via the provider's API
     * from the control plane (works before the site is deployed). $credentials
     * carries the provider-specific sending secrets the user supplied.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function verify(Site $site, string $domain, array $credentials, string $recipient): GuidedMailVerifyResult;
}
