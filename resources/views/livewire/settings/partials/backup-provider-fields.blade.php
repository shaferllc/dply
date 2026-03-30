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
            <div>
                <x-input-label :for="$formKey.'_s3_key'" :value="__('Access key')" />
                <x-text-input :id="$formKey.'_s3_key'" type="text" class="mt-1 block w-full" autocomplete="off"
                    @if($formKey === 'createForm') wire:model="createForm.s3.access_key" @else wire:model="editForm.s3.access_key" @endif />
                <x-input-error :messages="$errors->get($formKey.'.s3.access_key')" class="mt-2" />
            </div>
            <div>
                <x-input-label :for="$formKey.'_s3_secret'" :value="__('Access secret')" />
                <x-text-input :id="$formKey.'_s3_secret'" type="password" class="mt-1 block w-full" autocomplete="new-password"
                    @if($formKey === 'createForm') wire:model="createForm.s3.secret" @else wire:model="editForm.s3.secret" @endif />
                <x-input-error :messages="$errors->get($formKey.'.s3.secret')" class="mt-2" />
            </div>
            <div>
                <x-input-label :for="$formKey.'_s3_bucket'" :value="__('Bucket name')" />
                <x-text-input :id="$formKey.'_s3_bucket'" type="text" class="mt-1 block w-full" autocomplete="off"
                    @if($formKey === 'createForm') wire:model="createForm.s3.bucket" @else wire:model="editForm.s3.bucket" @endif />
                <x-input-error :messages="$errors->get($formKey.'.s3.bucket')" class="mt-2" />
            </div>
            <div>
                <x-input-label :for="$formKey.'_s3_region'" :value="__('Region name')" />
                <x-text-input :id="$formKey.'_s3_region'" type="text" class="mt-1 block w-full" placeholder="e.g. us-east-1, nl-ams1" autocomplete="off"
                    @if($formKey === 'createForm') wire:model="createForm.s3.region" @else wire:model="editForm.s3.region" @endif />
                <p class="mt-1 text-xs text-brand-moss">{{ __('Optionally enter a region name (for example nl-ams1).') }}</p>
                <x-input-error :messages="$errors->get($formKey.'.s3.region')" class="mt-2" />
            </div>
            <div>
                <x-input-label :for="$formKey.'_s3_endpoint'" :value="__('Endpoint')" />
                <x-text-input :id="$formKey.'_s3_endpoint'" type="text" class="mt-1 block w-full" autocomplete="off"
                    @if($formKey === 'createForm') wire:model="createForm.s3.endpoint" @else wire:model="editForm.s3.endpoint" @endif />
                <p class="mt-1 text-xs text-brand-moss">{{ __('Enter your S3-compatible endpoint (required for Custom S3 and Spaces; optional for AWS).') }}</p>
                <x-input-error :messages="$errors->get($formKey.'.s3.endpoint')" class="mt-2" />
            </div>
            <label class="flex items-start gap-3 cursor-pointer">
                <input type="checkbox" class="mt-1 rounded border-brand-ink/20 text-brand-ink focus:ring-brand-sage"
                    @if($formKey === 'createForm') wire:model.boolean="createForm.s3.use_path_style" @else wire:model.boolean="editForm.s3.use_path_style" @endif />
                <span class="text-sm text-brand-moss leading-relaxed">{{ __('Use path-style endpoint') }} <span class="text-brand-mist">({{ __('use a path suffix instead of a bucket subdomain') }})</span></span>
            </label>
        </div>
        @break

    @case(\App\Models\BackupConfiguration::PROVIDER_DROPBOX)
        <div>
            <x-input-label :for="$formKey.'_dbx'" :value="__('Access token')" />
            <x-text-input :id="$formKey.'_dbx'" type="password" class="mt-1 block w-full" autocomplete="off"
                @if($formKey === 'createForm') wire:model="createForm.dropbox.access_token" @else wire:model="editForm.dropbox.access_token" @endif />
            <x-input-error :messages="$errors->get($formKey.'.dropbox.access_token')" class="mt-2" />
        </div>
        @break

    @case(\App\Models\BackupConfiguration::PROVIDER_GOOGLE_DRIVE)
        <div class="space-y-4">
            <div>
                <x-input-label :for="$formKey.'_g_cid'" :value="__('Client ID')" />
                <x-text-input :id="$formKey.'_g_cid'" type="text" class="mt-1 block w-full" autocomplete="off"
                    @if($formKey === 'createForm') wire:model="createForm.google.client_id" @else wire:model="editForm.google.client_id" @endif />
                <x-input-error :messages="$errors->get($formKey.'.google.client_id')" class="mt-2" />
            </div>
            <div>
                <x-input-label :for="$formKey.'_g_cs'" :value="__('Client secret')" />
                <x-text-input :id="$formKey.'_g_cs'" type="password" class="mt-1 block w-full" autocomplete="off"
                    @if($formKey === 'createForm') wire:model="createForm.google.client_secret" @else wire:model="editForm.google.client_secret" @endif />
                <x-input-error :messages="$errors->get($formKey.'.google.client_secret')" class="mt-2" />
            </div>
            <div>
                <x-input-label :for="$formKey.'_g_rt'" :value="__('Refresh token')" />
                <x-text-input :id="$formKey.'_g_rt'" type="password" class="mt-1 block w-full" autocomplete="off"
                    @if($formKey === 'createForm') wire:model="createForm.google.refresh_token" @else wire:model="editForm.google.refresh_token" @endif />
                <x-input-error :messages="$errors->get($formKey.'.google.refresh_token')" class="mt-2" />
            </div>
        </div>
        @break

    @case(\App\Models\BackupConfiguration::PROVIDER_SFTP)
        <div class="space-y-4">
            <div>
                <x-input-label :for="$formKey.'_sf_host'" :value="__('Host')" />
                <x-text-input :id="$formKey.'_sf_host'" type="text" class="mt-1 block w-full" autocomplete="off"
                    @if($formKey === 'createForm') wire:model="createForm.sftp.host" @else wire:model="editForm.sftp.host" @endif />
                <x-input-error :messages="$errors->get($formKey.'.sftp.host')" class="mt-2" />
            </div>
            <div>
                <x-input-label :for="$formKey.'_sf_port'" :value="__('Port')" />
                <x-text-input :id="$formKey.'_sf_port'" type="text" inputmode="numeric" class="mt-1 block w-full" autocomplete="off"
                    @if($formKey === 'createForm') wire:model="createForm.sftp.port" @else wire:model="editForm.sftp.port" @endif />
                <x-input-error :messages="$errors->get($formKey.'.sftp.port')" class="mt-2" />
            </div>
            <div>
                <x-input-label :for="$formKey.'_sf_user'" :value="__('Username')" />
                <x-text-input :id="$formKey.'_sf_user'" type="text" class="mt-1 block w-full" autocomplete="username"
                    @if($formKey === 'createForm') wire:model="createForm.sftp.username" @else wire:model="editForm.sftp.username" @endif />
                <x-input-error :messages="$errors->get($formKey.'.sftp.username')" class="mt-2" />
            </div>
            <div>
                <x-input-label :for="$formKey.'_sf_pass'" :value="__('Password')" />
                <x-text-input :id="$formKey.'_sf_pass'" type="password" class="mt-1 block w-full" autocomplete="new-password"
                    @if($formKey === 'createForm') wire:model="createForm.sftp.password" @else wire:model="editForm.sftp.password" @endif />
                <x-input-error :messages="$errors->get($formKey.'.sftp.password')" class="mt-2" />
            </div>
            <div>
                <x-input-label :for="$formKey.'_sf_path'" :value="__('Remote path')" />
                <x-text-input :id="$formKey.'_sf_path'" type="text" class="mt-1 block w-full" autocomplete="off"
                    @if($formKey === 'createForm') wire:model="createForm.sftp.path" @else wire:model="editForm.sftp.path" @endif />
                <x-input-error :messages="$errors->get($formKey.'.sftp.path')" class="mt-2" />
            </div>
            <div>
                <x-input-label :for="$formKey.'_sf_pk'" :value="__('Private key (optional)')" />
                <textarea id="{{ $formKey }}_sf_pk" rows="4" class="mt-1 block w-full rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm font-mono shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                    @if($formKey === 'createForm') wire:model="createForm.sftp.private_key" @else wire:model="editForm.sftp.private_key" @endif></textarea>
                <x-input-error :messages="$errors->get($formKey.'.sftp.private_key')" class="mt-2" />
            </div>
        </div>
        @break

    @case(\App\Models\BackupConfiguration::PROVIDER_FTP)
        <div class="space-y-4">
            <div>
                <x-input-label :for="$formKey.'_ftp_host'" :value="__('Host')" />
                <x-text-input :id="$formKey.'_ftp_host'" type="text" class="mt-1 block w-full" autocomplete="off"
                    @if($formKey === 'createForm') wire:model="createForm.ftp.host" @else wire:model="editForm.ftp.host" @endif />
                <x-input-error :messages="$errors->get($formKey.'.ftp.host')" class="mt-2" />
            </div>
            <div>
                <x-input-label :for="$formKey.'_ftp_port'" :value="__('Port')" />
                <x-text-input :id="$formKey.'_ftp_port'" type="text" inputmode="numeric" class="mt-1 block w-full" autocomplete="off"
                    @if($formKey === 'createForm') wire:model="createForm.ftp.port" @else wire:model="editForm.ftp.port" @endif />
                <x-input-error :messages="$errors->get($formKey.'.ftp.port')" class="mt-2" />
            </div>
            <div>
                <x-input-label :for="$formKey.'_ftp_user'" :value="__('Username')" />
                <x-text-input :id="$formKey.'_ftp_user'" type="text" class="mt-1 block w-full" autocomplete="username"
                    @if($formKey === 'createForm') wire:model="createForm.ftp.username" @else wire:model="editForm.ftp.username" @endif />
                <x-input-error :messages="$errors->get($formKey.'.ftp.username')" class="mt-2" />
            </div>
            <div>
                <x-input-label :for="$formKey.'_ftp_pass'" :value="__('Password')" />
                <x-text-input :id="$formKey.'_ftp_pass'" type="password" class="mt-1 block w-full" autocomplete="new-password"
                    @if($formKey === 'createForm') wire:model="createForm.ftp.password" @else wire:model="editForm.ftp.password" @endif />
                <x-input-error :messages="$errors->get($formKey.'.ftp.password')" class="mt-2" />
            </div>
            <div>
                <x-input-label :for="$formKey.'_ftp_path'" :value="__('Remote path')" />
                <x-text-input :id="$formKey.'_ftp_path'" type="text" class="mt-1 block w-full" autocomplete="off"
                    @if($formKey === 'createForm') wire:model="createForm.ftp.path" @else wire:model="editForm.ftp.path" @endif />
                <x-input-error :messages="$errors->get($formKey.'.ftp.path')" class="mt-2" />
            </div>
        </div>
        @break

    @case(\App\Models\BackupConfiguration::PROVIDER_LOCAL)
        <div>
            <x-input-label :for="$formKey.'_local'" :value="__('Path')" />
            <x-text-input :id="$formKey.'_local'" type="text" class="mt-1 block w-full font-mono text-sm" autocomplete="off"
                @if($formKey === 'createForm') wire:model="createForm.local.path" @else wire:model="editForm.local.path" @endif />
            <p class="mt-1 text-xs text-brand-moss">{{ __('Absolute path on the server that runs backups (BYO agents only).') }}</p>
            <x-input-error :messages="$errors->get($formKey.'.local.path')" class="mt-2" />
        </div>
        @break

    @case(\App\Models\BackupConfiguration::PROVIDER_RCLONE)
        <div class="space-y-4">
            <div>
                <x-input-label :for="$formKey.'_rc_name'" :value="__('Remote name')" />
                <x-text-input :id="$formKey.'_rc_name'" type="text" class="mt-1 block w-full" autocomplete="off"
                    @if($formKey === 'createForm') wire:model="createForm.rclone.remote_name" @else wire:model="editForm.rclone.remote_name" @endif />
                <x-input-error :messages="$errors->get($formKey.'.rclone.remote_name')" class="mt-2" />
            </div>
            <div>
                <x-input-label :for="$formKey.'_rc_cfg'" :value="__('Extra config (optional)')" />
                <textarea id="{{ $formKey }}_rc_cfg" rows="6" class="mt-1 block w-full rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm font-mono shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                    @if($formKey === 'createForm') wire:model="createForm.rclone.config" @else wire:model="editForm.rclone.config" @endif placeholder="[remote]&#10;type = s3&#10;..."></textarea>
                <x-input-error :messages="$errors->get($formKey.'.rclone.config')" class="mt-2" />
            </div>
        </div>
        @break

    @default
        <p class="text-sm text-brand-moss">{{ __('Choose a storage provider.') }}</p>
@endswitch
