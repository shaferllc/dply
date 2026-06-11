{{-- Mail transport credential fields, shared by the mail attach modal.
     Expects $mailProvider in scope. Lets the operator reuse a saved
     MailCredential for the team, or enter new credentials with an optional
     "save for reuse". When a saved credential is selected the manager loads its
     credentials, so the provider-specific inputs are hidden. The from-address /
     from-name are rendered by the caller (they're per-site, not part of the
     reusable credential). --}}
@php
    $mailCreds = $this->mailCredentialsFor($mailProvider);
    $mailUsingSaved = ($bindingForm['credential_id'] ?? '') !== '';
    $mailPackage = \App\Services\Deploy\SiteBindingManager::MAIL_TRANSPORT_PACKAGES[$mailProvider] ?? null;
@endphp
@if ($mailCreds !== [])
    <div>
        <x-input-label for="binding_mail_credential" :value="__('Saved credentials')" />
        <div class="mt-1 flex items-center gap-2">
            <select id="binding_mail_credential" wire:model.live="bindingForm.credential_id" class="dply-input">
                <option value="">{{ __('Enter credentials…') }}</option>
                @foreach ($mailCreds as $cred)
                    <option value="{{ $cred['id'] }}">{{ $cred['label'] }}</option>
                @endforeach
            </select>
            @if ($mailUsingSaved)
                <button type="button" wire:click="deleteMailCredential('{{ $bindingForm['credential_id'] }}')" class="inline-flex shrink-0 items-center justify-center rounded-lg border border-rose-200 bg-white px-2.5 py-2 text-rose-700 transition-colors hover:bg-rose-50" title="{{ __('Remove these saved credentials') }}">
                    <x-heroicon-o-trash class="h-4 w-4" />
                </button>
            @endif
        </div>
    </div>
@endif
@unless ($mailUsingSaved)
    @if ($mailProvider === 'smtp')
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <x-input-label for="binding_mail_host" :value="__('SMTP host')" />
                <x-text-input id="binding_mail_host" wire:model="bindingForm.host" class="mt-1 block w-full font-mono text-sm" placeholder="smtp.example.com" />
            </div>
            <div>
                <x-input-label for="binding_mail_port" :value="__('Port')" />
                <x-text-input id="binding_mail_port" wire:model="bindingForm.port" class="mt-1 block w-full font-mono text-sm" placeholder="587" />
            </div>
            <div>
                <x-input-label for="binding_mail_encryption" :value="__('Encryption')" />
                <select id="binding_mail_encryption" wire:model="bindingForm.encryption" class="dply-input">
                    <option value="tls">{{ __('TLS / STARTTLS') }}</option>
                    <option value="ssl">{{ __('SSL (implicit, :465)') }}</option>
                    <option value="">{{ __('None') }}</option>
                </select>
            </div>
            <div>
                <x-input-label for="binding_mail_username" :value="__('Username')" />
                <x-text-input id="binding_mail_username" wire:model="bindingForm.username" class="mt-1 block w-full font-mono text-sm" placeholder="postmaster@example.com" />
            </div>
            <div>
                <x-input-label for="binding_mail_password" :value="__('Password')" />
                <x-text-input id="binding_mail_password" type="password" wire:model="bindingForm.password" class="mt-1 block w-full font-mono text-sm" />
            </div>
        </div>
    @elseif ($mailProvider === 'mailgun')
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <x-input-label for="binding_mail_domain" :value="__('Mailgun domain')" />
                <x-text-input id="binding_mail_domain" wire:model="bindingForm.domain" class="mt-1 block w-full font-mono text-sm" placeholder="mg.example.com" />
            </div>
            <div class="sm:col-span-2">
                <x-input-label for="binding_mail_secret" :value="__('Mailgun secret (API key)')" />
                <x-text-input id="binding_mail_secret" type="password" wire:model="bindingForm.secret" class="mt-1 block w-full font-mono text-sm" placeholder="key-…" />
            </div>
            <div class="sm:col-span-2">
                <x-input-label for="binding_mail_endpoint" :value="__('API endpoint')" />
                <x-text-input id="binding_mail_endpoint" wire:model="bindingForm.endpoint" class="mt-1 block w-full font-mono text-sm" placeholder="api.mailgun.net" />
                <p class="mt-1 text-[11px] text-brand-moss">{{ __('Use api.eu.mailgun.net for EU-region domains.') }}</p>
            </div>
        </div>
    @elseif ($mailProvider === 'postmark')
        <div>
            <x-input-label for="binding_mail_token" :value="__('Server token')" />
            <x-text-input id="binding_mail_token" type="password" wire:model="bindingForm.token" class="mt-1 block w-full font-mono text-sm" />
        </div>
    @elseif ($mailProvider === 'ses')
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <x-input-label for="binding_mail_ak" :value="__('Access key ID')" />
                <x-text-input id="binding_mail_ak" wire:model="bindingForm.access_key_id" class="mt-1 block w-full font-mono text-sm" />
            </div>
            <div>
                <x-input-label for="binding_mail_region" :value="__('Region')" />
                <x-text-input id="binding_mail_region" wire:model="bindingForm.region" class="mt-1 block w-full font-mono text-sm" placeholder="us-east-1" />
            </div>
            <div class="sm:col-span-2">
                <x-input-label for="binding_mail_sk" :value="__('Secret access key')" />
                <x-text-input id="binding_mail_sk" type="password" wire:model="bindingForm.secret_access_key" class="mt-1 block w-full font-mono text-sm" />
            </div>
        </div>
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-900">
            {{ __('SES reuses the AWS_* credentials. If this site also uses S3 object storage, both must point at the same AWS account.') }}
        </div>
    @elseif ($mailProvider === 'resend')
        <div>
            <x-input-label for="binding_mail_key" :value="__('Resend API key')" />
            <x-text-input id="binding_mail_key" type="password" wire:model="bindingForm.key" class="mt-1 block w-full font-mono text-sm" placeholder="re_…" />
        </div>
    @elseif ($mailProvider === 'sendgrid')
        <div>
            <x-input-label for="binding_mail_sgkey" :value="__('SendGrid API key')" />
            <x-text-input id="binding_mail_sgkey" type="password" wire:model="bindingForm.api_key" class="mt-1 block w-full font-mono text-sm" placeholder="SG.…" />
        </div>
    @elseif ($mailProvider === 'cloudflare')
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <x-input-label for="binding_mail_cfacct" :value="__('Account ID')" />
                <x-text-input id="binding_mail_cfacct" wire:model="bindingForm.account_id" class="mt-1 block w-full font-mono text-sm" />
            </div>
            <div>
                <x-input-label for="binding_mail_cfkey" :value="__('API key')" />
                <x-text-input id="binding_mail_cfkey" type="password" wire:model="bindingForm.key" class="mt-1 block w-full font-mono text-sm" />
            </div>
        </div>
    @endif

    @if (in_array($mailProvider, ['sendgrid', 'cloudflare'], true))
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-900">
            <p class="font-semibold">{{ __('One-time app change required') }}</p>
            <p class="mt-1">
                @if ($mailProvider === 'cloudflare')
                    {{ __("Laravel's default config/mail.php doesn't ship a cloudflare mailer. Add a 'cloudflare' => ['transport' => 'cloudflare'] entry to its mailers array and a 'cloudflare' => ['account_id' => env('CLOUDFLARE_ACCOUNT_ID'), 'key' => env('CLOUDFLARE_KEY')] block to config/services.php.") }}
                @else
                    {{ __("SendGrid isn't a built-in Laravel mailer. Register it once (e.g. Mail::extend('sendgrid', …) using Symfony's SendgridTransportFactory with SENDGRID_API_KEY) so MAIL_MAILER=sendgrid resolves.") }}
                @endif
            </p>
        </div>
    @endif

    @if ($mailPackage)
        <div class="rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-4 py-3 text-xs text-brand-moss">
            {{ __('Requires') }} <code class="font-mono font-semibold text-brand-ink">{{ $mailPackage }}</code> {{ __("in your app's") }} <code class="font-mono font-semibold text-brand-ink">composer.json</code>{{ __('. dply runs your app\'s own composer install — it won\'t add the package for you.') }}
        </div>
    @endif

    <div class="space-y-2">
        <label class="flex items-center gap-2 text-xs font-semibold text-brand-ink">
            <input type="checkbox" wire:model.live="bindingForm.save_credential" class="rounded border-brand-ink/25 text-brand-forest focus:ring-brand-sage/40" />
            {{ __('Save these credentials for reuse across the team') }}
        </label>
        @if ($bindingForm['save_credential'] ?? false)
            <x-text-input wire:model="bindingForm.credential_name" class="block w-full text-sm" :placeholder="__('Name (optional)')" />
        @endif
    </div>
@endunless
