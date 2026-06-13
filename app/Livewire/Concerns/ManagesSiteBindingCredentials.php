<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\AiCredential;
use App\Models\CaptchaCredential;
use App\Models\ErrorTrackingCredential;
use App\Models\LogDrainCredential;
use App\Models\OauthCredential;
use App\Models\PaymentCredential;
use App\Models\SearchCredential;
use App\Models\SmsCredential;
use Illuminate\Support\Facades\Gate;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesSiteBindingCredentials
{


    /**
     * Saved error-tracking credentials the site's org can reuse for $provider.
     *
     * @return list<array{id: string, label: string}>
     */
    public function errorTrackingCredentialsFor(string $provider): array
    {
        return ErrorTrackingCredential::query()
            ->where('organization_id', $this->site->organization_id)
            ->where('provider', $provider)
            ->orderBy('name')
            ->get()
            ->map(fn (ErrorTrackingCredential $c): array => [
                'id' => (string) $c->id,
                'label' => (string) $c->name,
            ])
            ->all();
    }

    public function deleteErrorTrackingCredential(string $credentialId): void
    {
        Gate::authorize('update', $this->site);

        $cred = ErrorTrackingCredential::query()
            ->where('organization_id', $this->site->organization_id)
            ->whereKey($credentialId)
            ->first();

        if (! $cred instanceof ErrorTrackingCredential) {
            return;
        }

        $cred->delete();

        if (($this->bindingForm['credential_id'] ?? '') === $credentialId) {
            $this->bindingForm['credential_id'] = '';
        }

        $this->toastSuccess(__('Saved error tracking credential removed.'));
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public function aiCredentialsFor(string $provider): array
    {
        return AiCredential::query()
            ->where('organization_id', $this->site->organization_id)
            ->where('provider', $provider)
            ->orderBy('name')
            ->get()
            ->map(fn (AiCredential $c): array => ['id' => (string) $c->id, 'label' => (string) $c->name])
            ->all();
    }

    public function deleteAiCredential(string $credentialId): void
    {
        Gate::authorize('update', $this->site);

        $cred = AiCredential::query()
            ->where('organization_id', $this->site->organization_id)
            ->whereKey($credentialId)
            ->first();

        if (! $cred instanceof AiCredential) {
            return;
        }

        $cred->delete();

        if (($this->bindingForm['credential_id'] ?? '') === $credentialId) {
            $this->bindingForm['credential_id'] = '';
        }

        $this->toastSuccess(__('Saved AI credential removed.'));
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public function captchaCredentialsFor(string $provider): array
    {
        return CaptchaCredential::query()
            ->where('organization_id', $this->site->organization_id)
            ->where('provider', $provider)
            ->orderBy('name')
            ->get()
            ->map(fn (CaptchaCredential $c): array => ['id' => (string) $c->id, 'label' => (string) $c->name])
            ->all();
    }

    public function deleteCaptchaCredential(string $credentialId): void
    {
        Gate::authorize('update', $this->site);

        $cred = CaptchaCredential::query()
            ->where('organization_id', $this->site->organization_id)
            ->whereKey($credentialId)
            ->first();

        if (! $cred instanceof CaptchaCredential) {
            return;
        }

        $cred->delete();

        if (($this->bindingForm['credential_id'] ?? '') === $credentialId) {
            $this->bindingForm['credential_id'] = '';
        }

        $this->toastSuccess(__('Saved CAPTCHA credential removed.'));
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public function smsCredentialsFor(string $provider): array
    {
        return SmsCredential::query()
            ->where('organization_id', $this->site->organization_id)
            ->where('provider', $provider)
            ->orderBy('name')
            ->get()
            ->map(fn (SmsCredential $c): array => ['id' => (string) $c->id, 'label' => (string) $c->name])
            ->all();
    }

    public function deleteSmsCredential(string $credentialId): void
    {
        Gate::authorize('update', $this->site);

        $cred = SmsCredential::query()
            ->where('organization_id', $this->site->organization_id)
            ->whereKey($credentialId)
            ->first();

        if (! $cred instanceof SmsCredential) {
            return;
        }

        $cred->delete();

        if (($this->bindingForm['credential_id'] ?? '') === $credentialId) {
            $this->bindingForm['credential_id'] = '';
        }

        $this->toastSuccess(__('Saved SMS credential removed.'));
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public function searchCredentialsFor(string $provider): array
    {
        return SearchCredential::query()
            ->where('organization_id', $this->site->organization_id)
            ->where('provider', $provider)
            ->orderBy('name')
            ->get()
            ->map(fn (SearchCredential $c): array => ['id' => (string) $c->id, 'label' => (string) $c->name])
            ->all();
    }

    public function deleteSearchCredential(string $credentialId): void
    {
        Gate::authorize('update', $this->site);

        $cred = SearchCredential::query()
            ->where('organization_id', $this->site->organization_id)
            ->whereKey($credentialId)
            ->first();

        if (! $cred instanceof SearchCredential) {
            return;
        }

        $cred->delete();

        if (($this->bindingForm['credential_id'] ?? '') === $credentialId) {
            $this->bindingForm['credential_id'] = '';
        }

        $this->toastSuccess(__('Saved search credential removed.'));
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public function paymentCredentialsFor(string $provider): array
    {
        return PaymentCredential::query()
            ->where('organization_id', $this->site->organization_id)
            ->where('provider', $provider)
            ->orderBy('name')
            ->get()
            ->map(fn (PaymentCredential $c): array => ['id' => (string) $c->id, 'label' => (string) $c->name])
            ->all();
    }

    public function deletePaymentCredential(string $credentialId): void
    {
        Gate::authorize('update', $this->site);

        $cred = PaymentCredential::query()
            ->where('organization_id', $this->site->organization_id)
            ->whereKey($credentialId)
            ->first();

        if (! $cred instanceof PaymentCredential) {
            return;
        }

        $cred->delete();

        if (($this->bindingForm['credential_id'] ?? '') === $credentialId) {
            $this->bindingForm['credential_id'] = '';
        }

        $this->toastSuccess(__('Saved payments credential removed.'));
    }

    /**
     * The Cashier webhook URL preview for the current payments provider, derived
     * from the site's primary hostname — shown in the modal so the operator can
     * register it. Null when the site has no public URL yet.
     */
    public function paymentsWebhookPreview(string $provider): ?string
    {
        $host = $this->site->primaryDomain()?->hostname;
        if (! is_string($host) || trim($host) === '') {
            $host = $this->site->testingHostname();
        }
        $host = strtolower(trim((string) $host));
        if ($host === '') {
            return null;
        }

        $path = $provider === 'paddle' ? '/paddle/webhook' : '/stripe/webhook';

        return 'https://'.$host.$path;
    }

    /**
     * The auto-derived OAuth redirect URL preview for the current provider,
     * shown in the modal (the footgun this binding removes). Null when the site
     * has no public URL yet.
     */
    public function oauthRedirectPreview(string $provider): ?string
    {
        $host = $this->site->primaryDomain()?->hostname;
        if (! is_string($host) || trim($host) === '') {
            $host = $this->site->testingHostname();
        }
        $host = strtolower(trim((string) $host));

        return $host !== '' ? 'https://'.$host.'/auth/'.$provider.'/callback' : null;
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public function oauthCredentialsFor(string $provider): array
    {
        return OauthCredential::query()
            ->where('organization_id', $this->site->organization_id)
            ->where('provider', $provider)
            ->orderBy('name')
            ->get()
            ->map(fn (OauthCredential $c): array => ['id' => (string) $c->id, 'label' => (string) $c->name])
            ->all();
    }

    public function deleteOauthCredential(string $credentialId): void
    {
        Gate::authorize('update', $this->site);

        $cred = OauthCredential::query()
            ->where('organization_id', $this->site->organization_id)
            ->whereKey($credentialId)
            ->first();

        if (! $cred instanceof OauthCredential) {
            return;
        }

        $cred->delete();

        if (($this->bindingForm['credential_id'] ?? '') === $credentialId) {
            $this->bindingForm['credential_id'] = '';
        }

        $this->toastSuccess(__('Saved OAuth credential removed.'));
    }

    /**
     * Saved log drain credentials the site's org can reuse for $provider.
     *
     * @return list<array{id: string, label: string}>
     */
    public function logDrainCredentialsFor(string $provider): array
    {
        return LogDrainCredential::query()
            ->where('organization_id', $this->site->organization_id)
            ->where('provider', $provider)
            ->orderBy('name')
            ->get()
            ->map(fn (LogDrainCredential $c): array => [
                'id' => (string) $c->id,
                'label' => (string) $c->name,
            ])
            ->all();
    }

    public function deleteLogDrainCredential(string $credentialId): void
    {
        Gate::authorize('update', $this->site);

        $cred = LogDrainCredential::query()
            ->where('organization_id', $this->site->organization_id)
            ->whereKey($credentialId)
            ->first();

        if (! $cred instanceof LogDrainCredential) {
            return;
        }

        $cred->delete();

        if (($this->bindingForm['credential_id'] ?? '') === $credentialId) {
            $this->bindingForm['credential_id'] = '';
        }

        $this->toastSuccess(__('Saved log drain credential removed.'));
    }
}
