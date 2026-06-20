<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Modules\Cloud\Cloudflare\CloudflareGuidedMailProvider;
use App\Support\Mail\Guided\GuidedMailGate;
use App\Support\Mail\Guided\GuidedMailStep;
use Illuminate\Support\Facades\Gate;

/**
 * Drives the inline "guided + verified" panel that appears in the mail binding
 * modal when the chosen provider is Cloudflare. It's a guided front door to the
 * ordinary `cloudflare` mail binding — verification happens from the control
 * plane (a real send via Cloudflare's API), so it works even before the site is
 * deployed.
 *
 * @property \App\Models\Site $site
 */
trait ManagesSiteBindingCloudflareEmail
{
    /** Last DNS pre-flight snapshot: ['spf'=>bool,'dkim'=>bool,'dmarc'=>bool,'detail'=>?string]. */
    /** @var array<string, mixed> */
    public array $cfEmailRecords = [];

    /** Whether the most recent control-plane verification send succeeded. */
    public bool $cfEmailVerified = false;

    /** Error from the most recent failed verification, for inline display. */
    public ?string $cfEmailVerifyError = null;

    /** Reset the guided panel's transient state (called when provider changes). */
    public function resetCloudflareEmailGuidance(): void
    {
        $this->cfEmailRecords = [];
        $this->cfEmailVerified = false;
        $this->cfEmailVerifyError = null;
    }

    /** Eligibility + the site's domains that can be set up for sending. */
    public function cloudflareEmailGate(): GuidedMailGate
    {
        return app(CloudflareGuidedMailProvider::class)->gate($this->site);
    }

    /**
     * The dashboard walkthrough for the currently selected sending domain.
     *
     * @return list<GuidedMailStep>
     */
    public function cloudflareEmailSteps(): array
    {
        $domain = $this->selectedCloudflareEmailDomain();

        return $domain === '' ? [] : app(CloudflareGuidedMailProvider::class)->onboardingSteps($domain);
    }

    /** Read the zone and report which SPF/DKIM/DMARC records are live. */
    public function pollCloudflareEmailRecords(): void
    {
        Gate::authorize('update', $this->site);

        $domain = $this->selectedCloudflareEmailDomain();
        if ($domain === '') {
            $this->toastError(__('Choose a sending domain first.'));

            return;
        }

        $status = app(CloudflareGuidedMailProvider::class)->pollRecords($this->site, $domain);
        $this->cfEmailRecords = [
            'spf' => $status->spf,
            'dkim' => $status->dkim,
            'dmarc' => $status->dmarc,
            'detail' => $status->detail,
        ];

        if ($status->detail !== null) {
            $this->toastError($status->detail);
        }
    }

    /** Prove the setup end-to-end with a real send through Cloudflare's API. */
    public function verifyCloudflareEmail(): void
    {
        Gate::authorize('update', $this->site);

        $domain = $this->selectedCloudflareEmailDomain();
        if ($domain === '') {
            $this->toastError(__('Choose a sending domain first.'));

            return;
        }

        $recipient = trim($this->mailTestRecipient) ?: (string) (auth()->user()?->email ?? '');
        if ($recipient === '' || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            $this->toastError(__('Enter a valid email address to send the verification to.'));

            return;
        }

        $result = app(CloudflareGuidedMailProvider::class)->verify(
            $this->site,
            $domain,
            [
                'account_id' => (string) ($this->bindingForm['account_id'] ?? ''),
                'key' => (string) ($this->bindingForm['key'] ?? ''),
                'from_address' => (string) ($this->bindingForm['from_address'] ?? ''),
                'from_name' => (string) ($this->bindingForm['from_name'] ?? ''),
            ],
            $recipient,
        );

        $this->cfEmailVerified = $result->ok;
        $this->cfEmailVerifyError = $result->error;

        if ($result->ok) {
            $this->toastSuccess(__('Verification email sent to :to — check the inbox (and spam).', ['to' => $recipient]));
        } else {
            $this->toastError($result->error ?? __('Verification failed.'));
        }
    }

    /** The chosen sending domain, defaulting to the site's primary. */
    private function selectedCloudflareEmailDomain(): string
    {
        $chosen = strtolower(trim((string) ($this->bindingForm['cf_domain'] ?? '')));
        if ($chosen !== '') {
            return $chosen;
        }

        return strtolower(trim((string) ($this->site->primaryDomain()?->hostname ?? '')));
    }
}
