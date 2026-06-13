<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Jobs\SendBindingTestEmailJob;
use App\Models\MailCredential;
use App\Models\SiteBinding;
use Illuminate\Support\Facades\Gate;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesSiteBindingMail
{


    /**
     * A blank failover/round-robin leg: provider slug + every per-provider cred
     * field (only the chosen provider's are used; the rest stay empty).
     *
     * @return array<string, string>
     */
    private function emptyMailLeg(string $provider = 'smtp'): array
    {
        return [
            'provider' => $provider,
            // smtp
            'host' => '', 'port' => '587', 'username' => '', 'password' => '', 'encryption' => 'tls',
            // mailgun
            'secret' => '', 'domain' => '', 'endpoint' => 'api.mailgun.net',
            // postmark
            'token' => '',
            // ses
            'access_key_id' => '', 'secret_access_key' => '', 'region' => '',
            // resend
            'key' => '',
        ];
    }

    /** Append a leg to the failover/round-robin chain in the open modal. */
    public function addMailLeg(): void
    {
        $legs = is_array($this->bindingForm['legs'] ?? null) ? $this->bindingForm['legs'] : [];
        $legs[] = $this->emptyMailLeg('mailgun');
        $this->bindingForm['legs'] = array_values($legs);
    }

    /** Remove a leg by index; the chain keeps at least two. */
    public function removeMailLeg(int $index): void
    {
        $legs = is_array($this->bindingForm['legs'] ?? null) ? array_values($this->bindingForm['legs']) : [];
        if (count($legs) <= 2) {
            return;
        }
        unset($legs[$index]);
        $this->bindingForm['legs'] = array_values($legs);
    }

    /**
     * The config/mail.php snippet the operator must paste for a failover /
     * round-robin chain — the one piece dply can't inject (the chain order
     * lives in committed code). Built from the legs currently in the modal.
     */
    public function mailFailoverSnippet(string $transport, array $legs): string
    {
        $slugs = [];
        foreach ($legs as $leg) {
            $p = is_array($leg) ? strtolower(trim((string) ($leg['provider'] ?? ''))) : '';
            if ($p !== '') {
                $slugs[] = "            '".$p."',";
            }
        }
        $list = implode("\n", $slugs);

        return "'mailers' => [\n"
            ."    '".$transport."' => [\n"
            ."        'transport' => '".$transport."',\n"
            ."        'mailers' => [\n".$list."\n        ],\n"
            ."    ],\n"
            ."    // … keep your existing mailer entries\n"
            ."],\n\n"
            ."'default' => env('MAIL_MAILER', '".$transport."'),";
    }

    /**
     * Saved mail credentials the site's org can reuse for $provider, for the
     * mail modal's "Use saved keys" picker.
     *
     * @return list<array{id: string, label: string}>
     */
    public function mailCredentialsFor(string $provider): array
    {
        return MailCredential::query()
            ->where('organization_id', $this->site->organization_id)
            ->where('provider', $provider)
            ->orderBy('name')
            ->get()
            ->map(fn (MailCredential $c): array => [
                'id' => (string) $c->id,
                'label' => (string) $c->name,
            ])
            ->all();
    }

    public function deleteMailCredential(string $credentialId): void
    {
        Gate::authorize('update', $this->site);

        $cred = MailCredential::query()
            ->where('organization_id', $this->site->organization_id)
            ->whereKey($credentialId)
            ->first();

        if (! $cred instanceof MailCredential) {
            return;
        }

        $cred->delete();

        if (($this->bindingForm['credential_id'] ?? '') === $credentialId) {
            $this->bindingForm['credential_id'] = '';
        }

        $this->toastSuccess(__('Saved mail credential removed.'));
    }

    /**
     * Send a test email from the site's server using the persisted mail
     * binding's transport, to confirm the deployed app can actually deliver.
     * Runs server-side (queued SSH) because that's the box that will send at
     * runtime — and it reuses the transport packages already in the app's
     * vendor/. Requires the console-action plumbing (deploy hub) + a deployed
     * site (vendor present); both are checked in the job / surfaced as copy.
     */
    public function sendBindingTestEmail(string $bindingId): void
    {
        Gate::authorize('update', $this->site);

        $binding = SiteBinding::query()
            ->where('site_id', $this->site->id)
            ->whereKey($bindingId)
            ->first();

        if (! $binding instanceof SiteBinding || $binding->type !== 'mail') {
            return;
        }

        if (! method_exists($this, 'seedQueuedConsoleAction') || ! method_exists($this, 'watchConsoleAction')) {
            $this->toastError(__('Sending a test email is available from the deploy hub.'));

            return;
        }

        $recipient = trim($this->mailTestRecipient) ?: (string) (auth()->user()?->email ?? '');
        if ($recipient === '' || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            $this->toastError(__('Enter a valid email address to send the test to.'));

            return;
        }

        $run = $this->seedQueuedConsoleAction('mail_test', __('Sending test email'));

        SendBindingTestEmailJob::dispatch(
            (string) $run->id,
            (string) $this->site->id,
            (string) $binding->id,
            $recipient,
        );

        $this->dispatch('dply-console-action-focus');
        $this->watchConsoleAction(
            $run,
            __('Test email sent to :to — check the inbox (and spam).', ['to' => $recipient]),
            __('The test email could not be sent — see the console for the transport error.'),
        );
    }
}
