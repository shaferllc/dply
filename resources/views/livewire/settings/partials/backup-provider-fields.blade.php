{{--
    $formKey is 'createForm' or 'editForm' (literal for wire:model).
    $form is the array (createForm / editForm) for @switch.
--}}
@props(['formKey', 'form'])

@switch($form['provider'] ?? '')
    @case(\App\Models\BackupConfiguration::PROVIDER_CUSTOM_S3)
    @case(\App\Models\BackupConfiguration::PROVIDER_AWS_S3)
    @case(\App\Models\BackupConfiguration::PROVIDER_DIGITALOCEAN_SPACES)
        <div class="space-y-4">
            {{-- S3 signing needs a key pair, so there is no OAuth for the
                 protocol itself — but dply can mint a Spaces key from the
                 DigitalOcean token already connected, which spares the operator
                 the console trip. Only offered when that token exists. --}}
            @if (($form['provider'] ?? '') === \App\Models\BackupConfiguration::PROVIDER_DIGITALOCEAN_SPACES
                && $formKey === 'destinationForm'
                && method_exists($this, 'loadDigitalOceanSpaces')
                && $this->provisionCanAutoMintSpaces())
                <div class="rounded-xl border border-brand-sage/30 bg-brand-sage/8 p-4">
                    @if (! $doSpacesKeyMinted)
                        <p class="text-sm font-semibold text-brand-ink">{{ __('Use your connected DigitalOcean account') }}</p>
                        <p class="mt-1 text-xs leading-relaxed text-brand-moss">
                            {{ __('dply creates a Spaces key from the DigitalOcean token you already connected and lists your Spaces — nothing to copy from the console.') }}
                        </p>
                        <p class="mt-1 text-xs leading-relaxed text-brand-moss/80">
                            {{ __('This creates a real Spaces access key on your DigitalOcean account, named dply-backups-<date>. It has account-wide Spaces access because listing your Spaces requires it — you can revoke it from the DigitalOcean console at any time.') }}
                        </p>
                        <button
                            type="button"
                            wire:click="loadDigitalOceanSpaces"
                            wire:loading.attr="disabled"
                            wire:target="loadDigitalOceanSpaces"
                            class="mt-3 inline-flex items-center gap-2 rounded-xl bg-[#0069ff] px-4 py-2 text-sm font-semibold text-white shadow-sm transition-opacity hover:opacity-90 disabled:opacity-60"
                        >
                            <span wire:loading.remove wire:target="loadDigitalOceanSpaces" class="inline-flex items-center gap-2">
                                <x-heroicon-o-key class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Create keys and find my Spaces') }}
                            </span>
                            <span wire:loading wire:target="loadDigitalOceanSpaces" class="inline-flex items-center gap-2">
                                <x-spinner variant="cream" size="sm" />
                                {{ __('Asking DigitalOcean…') }}
                            </span>
                        </button>
                    @else
                        <p class="flex items-center gap-1.5 text-sm font-semibold text-brand-forest">
                            <x-heroicon-m-check-circle class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Keys created and filled in below.') }}
                        </p>
                        @if ($doSpacesBuckets !== [])
                            <p class="mt-1 text-xs text-brand-moss">{{ __('Pick the Space to back up to:') }}</p>
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                @foreach ($doSpacesBuckets as $space)
                                    <button
                                        type="button"
                                        wire:click="useDigitalOceanSpace('{{ $space['bucket'] }}', '{{ $space['region'] }}')"
                                        @class([
                                            'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-semibold transition-colors',
                                            'border-brand-forest bg-brand-forest text-brand-cream' => ($form['s3']['bucket'] ?? '') === $space['bucket'],
                                            'border-brand-ink/15 bg-white text-brand-ink hover:border-brand-sage/40' => ($form['s3']['bucket'] ?? '') !== $space['bucket'],
                                        ])
                                    >
                                        {{ $space['bucket'] }}
                                        <span class="opacity-70">{{ $space['region'] }}</span>
                                    </button>
                                @endforeach
                            </div>
                        @else
                            <p class="mt-1 text-xs text-brand-moss">{{ __('No Spaces found on the account — enter the name and region below.') }}</p>
                        @endif
                    @endif
                </div>
            @endif

            <div>
                <x-input-label :for="$formKey.'_s3_key'" :value="__('Access key')" />
                <x-text-input :id="$formKey.'_s3_key'" type="text" class="mt-1 block w-full" autocomplete="off"
                    wire:model="{{ $formKey }}.s3.access_key" />
                <x-input-error :messages="$errors->get($formKey.'.s3.access_key')" class="mt-2" />
            </div>
            <div>
                <x-input-label :for="$formKey.'_s3_secret'" :value="__('Access secret')" />
                <x-text-input :id="$formKey.'_s3_secret'" type="password" class="mt-1 block w-full" autocomplete="new-password"
                    wire:model="{{ $formKey }}.s3.secret" />
                <x-input-error :messages="$errors->get($formKey.'.s3.secret')" class="mt-2" />
            </div>
            <div>
                <x-input-label :for="$formKey.'_s3_bucket'" :value="__('Bucket name')" />
                <x-text-input :id="$formKey.'_s3_bucket'" type="text" class="mt-1 block w-full" autocomplete="off"
                    wire:model="{{ $formKey }}.s3.bucket" />
                <x-input-error :messages="$errors->get($formKey.'.s3.bucket')" class="mt-2" />
            </div>
            <div>
                <x-input-label :for="$formKey.'_s3_region'" :value="__('Region name')" />
                <x-text-input :id="$formKey.'_s3_region'" type="text" class="mt-1 block w-full" placeholder="e.g. us-east-1, nl-ams1" autocomplete="off"
                    wire:model="{{ $formKey }}.s3.region" />
                <p class="mt-1 text-xs text-brand-moss">{{ __('Optionally enter a region name (for example nl-ams1).') }}</p>
                <x-input-error :messages="$errors->get($formKey.'.s3.region')" class="mt-2" />
            </div>
            <div>
                <x-input-label :for="$formKey.'_s3_endpoint'" :value="__('Endpoint')" />
                <x-text-input :id="$formKey.'_s3_endpoint'" type="text" class="mt-1 block w-full" autocomplete="off"
                    wire:model="{{ $formKey }}.s3.endpoint" />
                <p class="mt-1 text-xs text-brand-moss">{{ __('Enter your S3-compatible endpoint (required for Custom S3 and Spaces; optional for AWS).') }}</p>
                <x-input-error :messages="$errors->get($formKey.'.s3.endpoint')" class="mt-2" />
            </div>
            <label class="flex items-start gap-3 cursor-pointer">
                <input type="checkbox" class="mt-1 rounded border-brand-ink/20 text-brand-ink focus:ring-brand-sage"
                    wire:model.boolean="{{ $formKey }}.s3.use_path_style" />
                <span class="text-sm text-brand-moss leading-relaxed">{{ __('Use path-style endpoint') }} <span class="text-brand-mist">({{ __('use a path suffix instead of a bucket subdomain') }})</span></span>
            </label>

            @if (($form['provider'] ?? '') === \App\Models\BackupConfiguration::PROVIDER_AWS_S3)
                @php $awsClasses = (array) config('object_storage.providers.aws_s3.storage_classes', []); @endphp
                <div>
                    <x-input-label :for="$formKey.'_s3_class'" :value="__('Storage class (cold storage)')" />
                    <select :id="$formKey.'_s3_class'" wire:model.live="{{ $formKey }}.s3.storage_class" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage">
                        <option value="">{{ __('Standard (default)') }}</option>
                        @foreach ($awsClasses as $classKey => $classMeta)
                            @continue($classKey === 'STANDARD')
                            <option value="{{ $classKey }}">{{ $classMeta['label'] ?? $classKey }}</option>
                        @endforeach
                    </select>
                    @php $selClass = $awsClasses[$form['s3']['storage_class'] ?? ''] ?? null; @endphp
                    @if ($selClass)
                        <p @class(['mt-1 text-xs', 'text-amber-700' => ($selClass['restore'] ?? false), 'text-brand-moss' => ! ($selClass['restore'] ?? false)])>
                            {{ $selClass['note'] ?? '' }}
                        </p>
                    @else
                        <p class="mt-1 text-xs text-brand-moss">{{ __('Backups are written once and rarely read — a colder class (e.g. Glacier Instant Retrieval) cuts storage cost while staying instantly downloadable.') }}</p>
                    @endif
                    <x-input-error :messages="$errors->get($formKey.'.s3.storage_class')" class="mt-2" />
                </div>
            @endif
        </div>
        @break

    @case(\App\Models\BackupConfiguration::PROVIDER_DROPBOX)
        <div class="space-y-4">
            {{-- Getting a Dropbox credential is a multi-step trip through their
                 App Console, and the console's own "Generate" button hands out a
                 token that dies in ~4 hours. Spelling out the durable path here
                 is the difference between a backup that keeps working and one
                 that silently stops overnight. --}}
            @php
                $dropboxOAuth = \App\Http\Controllers\Credentials\BackupStorageOAuthController::dropboxConfigured();
            @endphp

            @if ($dropboxOAuth)
                {{-- One click beats the App Console round trip, and the flow always
                     requests offline access, so the destination gets a refresh
                     token rather than one that dies in four hours. Offered while
                     editing too: swapping an expiring token for a durable one is
                     the single most likely reason to open this screen. --}}
                @php
                    $dbxReconnecting = ! empty($destination_editing_id);
                    $dbxOAuthUrl = $dbxReconnecting
                        ? route('credentials.oauth.dropbox.redirect', ['destination' => $destination_editing_id])
                        : route('credentials.oauth.dropbox.redirect');
                @endphp
                <div class="rounded-xl border border-brand-sage/30 bg-brand-sage/8 p-4 text-center">
                    <p class="text-sm font-semibold text-brand-ink">
                        {{ $dbxReconnecting ? __('Reconnect with Dropbox') : __('Connect in one click') }}
                    </p>
                    <p class="mx-auto mt-1 max-w-sm text-xs leading-relaxed text-brand-moss">
                        {{ $dbxReconnecting
                            ? __('Re-authorize this destination and dply swaps in a refresh token that never expires. Its name and folder stay as they are.')
                            : __('Sign in with Dropbox and dply stores a refresh token that keeps scheduled dumps working. No app key to copy.') }}
                    </p>
                    {{-- Dropbox rejects the authorize call with "scope_not_granted"
                         when the app has no scopes ticked, and it does so on its own
                         error page — we never see the callback, so there is nowhere
                         to explain it after the fact. Say it before they click. --}}
                    <p class="mx-auto mt-2 max-w-sm rounded-lg bg-brand-gold/15 px-2.5 py-1.5 text-2xs leading-relaxed text-amber-900">
                        {{ __('First time? The Dropbox app needs files.content.write and files.content.read ticked on its Permissions tab (then Submit). Without them Dropbox answers "no scope requested can be granted".') }}
                    </p>
                    <a
                        href="{{ $dbxOAuthUrl }}"
                        class="mt-3 inline-flex items-center gap-2 rounded-xl bg-[#0061FF] px-4 py-2 text-sm font-semibold text-white shadow-sm transition-opacity hover:opacity-90"
                    >
                        <x-heroicon-o-cloud-arrow-up class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ $dbxReconnecting ? __('Reconnect with Dropbox') : __('Continue with Dropbox') }}
                    </a>
                </div>

                <div class="flex items-center gap-3">
                    <span class="h-px flex-1 bg-brand-ink/10"></span>
                    <span class="text-2xs uppercase tracking-wide text-brand-mist">{{ __('or set it up manually') }}</span>
                    <span class="h-px flex-1 bg-brand-ink/10"></span>
                </div>
            @endif

            <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/25 p-3">
                <p class="text-xs font-semibold text-brand-ink">{{ __('Setting up Dropbox') }}</p>
                <ol class="mt-2 list-decimal space-y-1 pl-4 text-xs leading-relaxed text-brand-moss">
                    <li>
                        {{ __('Open the') }}
                        <a href="https://www.dropbox.com/developers" target="_blank" rel="noopener noreferrer" class="font-semibold text-brand-sage hover:text-brand-ink">{{ __('Dropbox developers site') }}</a>,
                        {{ __('open the App Console, and choose Create app.') }}
                    </li>
                    <li>{{ __('Pick Scoped access, then App folder — that limits dply to one folder instead of your whole Dropbox.') }}</li>
                    <li>{{ __('On the Permissions tab tick files.content.write and files.content.read, then Submit. Do this before generating any token, or the token will lack the scopes.') }}</li>
                    <li>{{ __('On the Settings tab copy the App key and App secret into the fields below.') }}</li>
                    <li>{{ __('Get a refresh token: visit the authorize URL below, approve, and exchange the code shown. The refresh token never expires.') }}</li>
                </ol>
                <p class="mt-2 text-2xs leading-relaxed text-brand-mist">
                    {{ __('In a hurry? Leave the top three blank and paste a Generated access token from the Settings tab instead — but it stops working in about 4 hours, so use it only for a one-off test.') }}
                </p>
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <x-input-label :for="$formKey.'_dbx_key'" :value="__('App key')" />
                    <x-text-input :id="$formKey.'_dbx_key'" type="text" class="mt-1 block w-full" autocomplete="off"
                        wire:model="{{ $formKey }}.dropbox.app_key" />
                    <x-input-error :messages="$errors->get($formKey.'.dropbox.app_key')" class="mt-2" />
                </div>
                <div>
                    <x-input-label :for="$formKey.'_dbx_secret'" :value="__('App secret')" />
                    <x-text-input :id="$formKey.'_dbx_secret'" type="password" class="mt-1 block w-full" autocomplete="off"
                        wire:model="{{ $formKey }}.dropbox.app_secret" />
                    <x-input-error :messages="$errors->get($formKey.'.dropbox.app_secret')" class="mt-2" />
                </div>
            </div>

            @php
                $dbxAppKey = trim((string) ($form['dropbox']['app_key'] ?? ''));
            @endphp
            @if ($dbxAppKey !== '')
                <div class="rounded-lg border border-brand-sage/30 bg-brand-sage/8 p-3">
                    <p class="text-xs font-semibold text-brand-ink">{{ __('Authorize this app') }}</p>
                    <p class="mt-1 text-xs leading-relaxed text-brand-moss">
                        {{ __('Open this URL, approve access, and Dropbox shows an authorization code:') }}
                    </p>
                    <a
                        href="https://www.dropbox.com/oauth2/authorize?client_id={{ urlencode($dbxAppKey) }}&response_type=code&token_access_type=offline"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="mt-1.5 block break-all font-mono text-2xs text-brand-sage hover:text-brand-ink"
                    >https://www.dropbox.com/oauth2/authorize?client_id={{ $dbxAppKey }}&response_type=code&token_access_type=offline</a>
                    <p class="mt-2 text-xs leading-relaxed text-brand-moss">
                        {{ __('Then swap that code for a refresh token (run locally, it prints refresh_token):') }}
                    </p>
                    <code class="mt-1.5 block break-all rounded bg-brand-ink px-2 py-1.5 font-mono text-2xs text-brand-cream">curl -u {{ $dbxAppKey }}:APP_SECRET -d code=YOUR_CODE -d grant_type=authorization_code https://api.dropbox.com/oauth2/token</code>
                </div>
            @endif

            <div>
                <x-input-label :for="$formKey.'_dbx_refresh'" :value="__('Refresh token')" />
                <x-text-input :id="$formKey.'_dbx_refresh'" type="password" class="mt-1 block w-full" autocomplete="off"
                    wire:model="{{ $formKey }}.dropbox.refresh_token" />
                <p class="mt-1 text-xs text-brand-mist">{{ __('Never expires — this is what keeps scheduled dumps working.') }}</p>
                <x-input-error :messages="$errors->get($formKey.'.dropbox.refresh_token')" class="mt-2" />
            </div>

            <div>
                <x-input-label :for="$formKey.'_dbx'" :value="__('Access token (one-off test only)')" />
                <x-text-input :id="$formKey.'_dbx'" type="password" class="mt-1 block w-full" autocomplete="off"
                    wire:model="{{ $formKey }}.dropbox.access_token" />
                <p class="mt-1 text-xs text-brand-mist">{{ __('Expires in about 4 hours. Leave blank if you supplied a refresh token above.') }}</p>
                <x-input-error :messages="$errors->get($formKey.'.dropbox.access_token')" class="mt-2" />
            </div>

            <div>
                <x-input-label :for="$formKey.'_dbx_path'" :value="__('Folder (optional)')" />
                <x-text-input :id="$formKey.'_dbx_path'" type="text" class="mt-1 block w-full" autocomplete="off"
                    placeholder="/backups"
                    wire:model="{{ $formKey }}.dropbox.path" />
                <p class="mt-1 text-xs text-brand-mist">{{ __('Path inside the app folder. Leave blank to write to its root.') }}</p>
            </div>
        </div>
        @break

    @case(\App\Models\BackupConfiguration::PROVIDER_GOOGLE_DRIVE)
        <div class="space-y-4">
            {{-- Drive needs a full OAuth client, and the refresh token only comes
                 back when access_type=offline + prompt=consent are both set — the
                 single most common way this setup fails silently. --}}
            @php
                $gdOAuth = \App\Http\Controllers\Credentials\BackupStorageOAuthController::googleDriveConfigured();
                $gdReconnecting = ! empty($destination_editing_id);
            @endphp

            @if ($gdOAuth)
                <div class="rounded-xl border border-brand-sage/30 bg-brand-sage/8 p-4 text-center">
                    <p class="text-sm font-semibold text-brand-ink">
                        {{ $gdReconnecting ? __('Reconnect with Google') : __('Connect in one click') }}
                    </p>
                    <p class="mx-auto mt-1 max-w-sm text-xs leading-relaxed text-brand-moss">
                        {{ $gdReconnecting
                            ? __('Re-authorize this destination and dply swaps in a fresh refresh token. Its name and folder stay as they are.')
                            : __('Sign in with Google and dply stores a refresh token that keeps scheduled dumps working. No client secret to copy.') }}
                    </p>
                    <p class="mx-auto mt-2 max-w-sm rounded-lg bg-brand-gold/15 px-2.5 py-1.5 text-2xs leading-relaxed text-amber-900">
                        {{ __('dply asks only for drive.file — it can touch the files it creates and nothing else in your Drive.') }}
                    </p>
                    {{-- The 7-day expiry is the quiet killer here: a destination
                         connected from a Testing-mode app works fine, then stops a
                         week later with no configuration change to point at.
                         drive.file is non-sensitive, so publishing needs no
                         Google verification — it is purely a button. --}}
                    <p class="mx-auto mt-1.5 max-w-sm rounded-lg bg-brand-gold/15 px-2.5 py-1.5 text-2xs leading-relaxed text-amber-900">
                        {{ __('Publish your Google Cloud consent screen before connecting. While it is in Testing, Google expires the refresh token after 7 days and scheduled dumps stop. drive.file is non-sensitive, so publishing needs no verification review.') }}
                    </p>
                    <a
                        href="{{ $gdReconnecting
                            ? route('credentials.oauth.google-drive.redirect', ['destination' => $destination_editing_id])
                            : route('credentials.oauth.google-drive.redirect') }}"
                        class="mt-3 inline-flex items-center gap-2 rounded-xl bg-[#1a73e8] px-4 py-2 text-sm font-semibold text-white shadow-sm transition-opacity hover:opacity-90"
                    >
                        <x-heroicon-o-cloud-arrow-up class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ $gdReconnecting ? __('Reconnect with Google') : __('Continue with Google') }}
                    </a>
                </div>

                <div class="flex items-center gap-3">
                    <span class="h-px flex-1 bg-brand-ink/10"></span>
                    <span class="text-2xs uppercase tracking-wide text-brand-mist">{{ __('or set it up manually') }}</span>
                    <span class="h-px flex-1 bg-brand-ink/10"></span>
                </div>
            @endif

            <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/25 p-3">
                <p class="text-xs font-semibold text-brand-ink">{{ __('Setting up Google Drive') }}</p>
                <ol class="mt-2 list-decimal space-y-1 pl-4 text-xs leading-relaxed text-brand-moss">
                    <li>
                        {{ __('In the') }}
                        <a href="https://console.cloud.google.com/apis/library/drive.googleapis.com" target="_blank" rel="noopener noreferrer" class="font-semibold text-brand-sage hover:text-brand-ink">{{ __('Google Cloud console') }}</a>
                        {{ __('enable the Google Drive API for your project.') }}
                    </li>
                    <li>{{ __('Under Credentials create an OAuth client ID of type Desktop app, then copy its client ID and secret below.') }}</li>
                    <li>{{ __('Authorize it with access_type=offline and prompt=consent — without both, Google returns no refresh token and dply cannot keep the destination alive.') }}</li>
                    <li>{{ __('Exchange the resulting code for a refresh token and paste it below.') }}</li>
                    <li>{{ __('Optional: open the destination folder in Drive and copy the id from its URL, so dumps land there instead of My Drive.') }}</li>
                </ol>
                <p class="mt-2 text-2xs leading-relaxed text-brand-mist">
                    {{ __('dply exchanges the refresh token for a short-lived access token on every run — your client secret stays here and never reaches a server.') }}
                </p>
            </div>

            <div>
                <x-input-label :for="$formKey.'_g_cid'" :value="__('Client ID')" />
                <x-text-input :id="$formKey.'_g_cid'" type="text" class="mt-1 block w-full" autocomplete="off"
                    wire:model="{{ $formKey }}.google.client_id" />
                <x-input-error :messages="$errors->get($formKey.'.google.client_id')" class="mt-2" />
            </div>
            <div>
                <x-input-label :for="$formKey.'_g_cs'" :value="__('Client secret')" />
                <x-text-input :id="$formKey.'_g_cs'" type="password" class="mt-1 block w-full" autocomplete="off"
                    wire:model="{{ $formKey }}.google.client_secret" />
                <x-input-error :messages="$errors->get($formKey.'.google.client_secret')" class="mt-2" />
            </div>
            <div>
                <x-input-label :for="$formKey.'_g_rt'" :value="__('Refresh token')" />
                <x-text-input :id="$formKey.'_g_rt'" type="password" class="mt-1 block w-full" autocomplete="off"
                    wire:model="{{ $formKey }}.google.refresh_token" />
                <x-input-error :messages="$errors->get($formKey.'.google.refresh_token')" class="mt-2" />
            </div>
            <div>
                <x-input-label :for="$formKey.'_g_folder'" :value="__('Folder ID (optional)')" />
                <x-text-input :id="$formKey.'_g_folder'" type="text" class="mt-1 block w-full" autocomplete="off"
                    wire:model="{{ $formKey }}.google.folder_id" />
                <p class="mt-1 text-xs text-brand-mist">{{ __('From the folder URL in Drive. Leave blank to write to My Drive.') }}</p>
                <x-input-error :messages="$errors->get($formKey.'.google.refresh_token')" class="mt-2" />
            </div>
        </div>
        @break

    @case(\App\Models\BackupConfiguration::PROVIDER_SFTP)
        <div class="space-y-4">
            <div>
                <x-input-label :for="$formKey.'_sf_host'" :value="__('Host')" />
                <x-text-input :id="$formKey.'_sf_host'" type="text" class="mt-1 block w-full" autocomplete="off"
                    wire:model="{{ $formKey }}.sftp.host" />
                <x-input-error :messages="$errors->get($formKey.'.sftp.host')" class="mt-2" />
            </div>
            <div>
                <x-input-label :for="$formKey.'_sf_port'" :value="__('Port')" />
                <x-text-input :id="$formKey.'_sf_port'" type="text" inputmode="numeric" class="mt-1 block w-full" autocomplete="off"
                    wire:model="{{ $formKey }}.sftp.port" />
                <x-input-error :messages="$errors->get($formKey.'.sftp.port')" class="mt-2" />
            </div>
            <div>
                <x-input-label :for="$formKey.'_sf_user'" :value="__('Username')" />
                <x-text-input :id="$formKey.'_sf_user'" type="text" class="mt-1 block w-full" autocomplete="username"
                    wire:model="{{ $formKey }}.sftp.username" />
                <x-input-error :messages="$errors->get($formKey.'.sftp.username')" class="mt-2" />
            </div>
            <div>
                <x-input-label :for="$formKey.'_sf_pass'" :value="__('Password')" />
                <x-text-input :id="$formKey.'_sf_pass'" type="password" class="mt-1 block w-full" autocomplete="new-password"
                    wire:model="{{ $formKey }}.sftp.password" />
                <x-input-error :messages="$errors->get($formKey.'.sftp.password')" class="mt-2" />
            </div>
            <div>
                <x-input-label :for="$formKey.'_sf_path'" :value="__('Remote path')" />
                <x-text-input :id="$formKey.'_sf_path'" type="text" class="mt-1 block w-full" autocomplete="off"
                    wire:model="{{ $formKey }}.sftp.path" />
                <x-input-error :messages="$errors->get($formKey.'.sftp.path')" class="mt-2" />
            </div>
            <div>
                <x-input-label :for="$formKey.'_sf_pk'" :value="__('Private key (optional)')" />
                <textarea id="{{ $formKey }}_sf_pk" rows="4" class="mt-1 block w-full rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm font-mono shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                    wire:model="{{ $formKey }}.sftp.private_key"></textarea>
                <x-input-error :messages="$errors->get($formKey.'.sftp.private_key')" class="mt-2" />
            </div>
        </div>
        @break

    @case(\App\Models\BackupConfiguration::PROVIDER_FTP)
        <div class="space-y-4">
            <div>
                <x-input-label :for="$formKey.'_ftp_host'" :value="__('Host')" />
                <x-text-input :id="$formKey.'_ftp_host'" type="text" class="mt-1 block w-full" autocomplete="off"
                    wire:model="{{ $formKey }}.ftp.host" />
                <x-input-error :messages="$errors->get($formKey.'.ftp.host')" class="mt-2" />
            </div>
            <div>
                <x-input-label :for="$formKey.'_ftp_port'" :value="__('Port')" />
                <x-text-input :id="$formKey.'_ftp_port'" type="text" inputmode="numeric" class="mt-1 block w-full" autocomplete="off"
                    wire:model="{{ $formKey }}.ftp.port" />
                <x-input-error :messages="$errors->get($formKey.'.ftp.port')" class="mt-2" />
            </div>
            <div>
                <x-input-label :for="$formKey.'_ftp_user'" :value="__('Username')" />
                <x-text-input :id="$formKey.'_ftp_user'" type="text" class="mt-1 block w-full" autocomplete="username"
                    wire:model="{{ $formKey }}.ftp.username" />
                <x-input-error :messages="$errors->get($formKey.'.ftp.username')" class="mt-2" />
            </div>
            <div>
                <x-input-label :for="$formKey.'_ftp_pass'" :value="__('Password')" />
                <x-text-input :id="$formKey.'_ftp_pass'" type="password" class="mt-1 block w-full" autocomplete="new-password"
                    wire:model="{{ $formKey }}.ftp.password" />
                <x-input-error :messages="$errors->get($formKey.'.ftp.password')" class="mt-2" />
            </div>
            <div>
                <x-input-label :for="$formKey.'_ftp_path'" :value="__('Remote path')" />
                <x-text-input :id="$formKey.'_ftp_path'" type="text" class="mt-1 block w-full" autocomplete="off"
                    wire:model="{{ $formKey }}.ftp.path" />
                <x-input-error :messages="$errors->get($formKey.'.ftp.path')" class="mt-2" />
            </div>
        </div>
        @break

    @case(\App\Models\BackupConfiguration::PROVIDER_RCLONE)
        <div class="space-y-4">
            <div>
                <x-input-label :for="$formKey.'_rc_name'" :value="__('Remote name')" />
                <x-text-input :id="$formKey.'_rc_name'" type="text" class="mt-1 block w-full" autocomplete="off"
                    wire:model="{{ $formKey }}.rclone.remote_name" />
                <x-input-error :messages="$errors->get($formKey.'.rclone.remote_name')" class="mt-2" />
                {{-- rclone is the only transport whose binary dply does not ship
                     or install: curl is on every box, rclone rarely is. Saying so
                     up front beats a "command not found" on the first scheduled
                     run at 3am. --}}
                <p class="mt-2 rounded-lg bg-brand-gold/15 px-2.5 py-1.5 text-2xs leading-relaxed text-amber-900">
                    {{ __('rclone must already be installed on every server that backs up to this destination — dply runs it there, it does not install it. Check with "rclone version" over SSH; SFTP, FTP and S3 need nothing extra.') }}
                </p>
            </div>
            <div>
                <x-input-label :for="$formKey.'_rc_cfg'" :value="__('Extra config (optional)')" />
                <textarea id="{{ $formKey }}_rc_cfg" rows="6" class="mt-1 block w-full rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm font-mono shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                    wire:model="{{ $formKey }}.rclone.config" placeholder="[remote]&#10;type = s3&#10;..."></textarea>
                <x-input-error :messages="$errors->get($formKey.'.rclone.config')" class="mt-2" />
            </div>
        </div>
        @break

    @default
        <p class="text-sm text-brand-moss">{{ __('Choose a storage provider.') }}</p>
@endswitch
