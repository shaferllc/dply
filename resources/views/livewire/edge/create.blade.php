<div class="mx-auto max-w-3xl px-6 py-10">
    <header class="mb-8">
        <h1 class="text-3xl font-semibold text-slate-900">{{ __('Deploy a container app') }}</h1>
        <p class="mt-2 text-sm text-slate-600">{{ __('Push a Docker image to the dply edge platform. We provision it on a managed container backend (DigitalOcean App Platform or AWS App Runner) under the hood — you get global HTTPS, auto-scaling, and zero-config TLS.') }}</p>
    </header>

    @if ($connectedBackends->isEmpty())
        <div class="rounded-2xl border border-amber-200 bg-amber-50/60 p-5 text-sm text-amber-900">
            <p class="font-semibold">{{ __('No container backend connected') }}</p>
            <p class="mt-1">{{ __('Connect a DigitalOcean App Platform or AWS App Runner credential first — that\'s the cloud account dply uses to run your container under the hood.') }}</p>
            <p class="mt-3">
                <a href="{{ route('credentials.index', ['provider' => 'digitalocean_app_platform']) }}" wire:navigate class="font-medium text-amber-900 underline">{{ __('Connect DigitalOcean') }}</a>
                <span class="mx-2 text-amber-400">·</span>
                <a href="{{ route('credentials.index', ['provider' => 'aws_app_runner']) }}" wire:navigate class="font-medium text-amber-900 underline">{{ __('Connect AWS App Runner') }}</a>
            </p>
        </div>
    @endif

    <form wire:submit="deploy" class="mt-8 space-y-6">
        <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div role="tablist" aria-label="{{ __('Deployment source') }}" class="mb-5 inline-flex rounded-xl border border-slate-200 bg-slate-50 p-1 text-sm">
                <button type="button" role="tab" aria-selected="{{ $mode === 'image' ? 'true' : 'false' }}" wire:click="$set('mode', 'image')"
                    @class([
                        'rounded-lg px-3 py-1.5 font-medium transition',
                        'bg-white text-slate-900 shadow-sm' => $mode === 'image',
                        'text-slate-600 hover:text-slate-900' => $mode !== 'image',
                    ])>{{ __('Container image') }}</button>
                <button type="button" role="tab" aria-selected="{{ $mode === 'source' ? 'true' : 'false' }}" wire:click="$set('mode', 'source')"
                    @class([
                        'rounded-lg px-3 py-1.5 font-medium transition',
                        'bg-white text-slate-900 shadow-sm' => $mode === 'source',
                        'text-slate-600 hover:text-slate-900' => $mode !== 'source',
                    ])>{{ __('Deploy from source') }}</button>
            </div>

            <div class="space-y-4">
                <div>
                    <x-input-label for="name" :value="__('App name')" />
                    <x-text-input id="name" wire:model="name" type="text" class="mt-1 block w-full" required placeholder="acme-api" />
                    <x-input-error :messages="$errors->get('name')" class="mt-2" />
                </div>
                @if ($mode === 'source')
                    @if ($backend === 'aws_app_runner' && ! $awsSourceReady)
                        <div data-testid="aws-github-connection-missing" class="rounded-xl border border-amber-300 bg-amber-50/70 p-4 text-sm text-amber-900">
                            <p class="font-semibold">{{ __('AWS App Runner needs a GitHub connection') }}</p>
                            <p class="mt-1">{{ __('App Runner can only build from a GitHub repo when an authorized connection ARN is attached to the credential. Set up the connection in the AWS console, then store the ARN as github_connection_arn on this credential.') }}</p>
                        </div>
                    @endif
                    <div>
                        <x-input-label for="repo" :value="__('GitHub repo')" />
                        <x-text-input id="repo" wire:model="repo" type="text" class="mt-1 block w-full font-mono" required placeholder="acme/api" />
                        <p class="mt-1 text-xs text-slate-500">{{ __('owner/name or full GitHub URL. The backend pulls and builds it for you.') }}</p>
                        <x-input-error :messages="$errors->get('repo')" class="mt-2" />
                    </div>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <x-input-label for="branch" :value="__('Branch')" />
                            <x-text-input id="branch" wire:model="branch" type="text" class="mt-1 block w-full font-mono" required placeholder="main" />
                            <x-input-error :messages="$errors->get('branch')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="dockerfile_path" :value="__('Dockerfile path (optional)')" />
                            <x-text-input id="dockerfile_path" wire:model="dockerfile_path" type="text" class="mt-1 block w-full font-mono" placeholder="Dockerfile" />
                            <p class="mt-1 text-xs text-slate-500">{{ __('Leave blank for buildpack auto-detection.') }}</p>
                        </div>
                    </div>
                    <label class="flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" wire:model="deploy_on_push" class="rounded border-slate-300">
                        {{ __('Auto-deploy on push to this branch') }}
                    </label>
                @else
                    <div>
                        <x-input-label for="image" :value="__('Container image')" />
                        <x-text-input id="image" wire:model="image" type="text" class="mt-1 block w-full" required placeholder="ghcr.io/acme/api:v1.2.3" />
                        <p class="mt-1 text-xs text-slate-500">{{ __('Public registry images work out of the box. For private images, connect a registry credential first.') }}</p>
                        <x-input-error :messages="$errors->get('image')" class="mt-2" />
                    </div>
                @endif
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <x-input-label for="port" :value="__('HTTP port')" />
                        <x-text-input id="port" wire:model="port" type="number" min="1" max="65535" class="mt-1 block w-full" required />
                        <x-input-error :messages="$errors->get('port')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="backend" :value="__('Backend')" />
                        <select id="backend" wire:model.live="backend" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm">
                            <option value="auto">{{ __('Let dply choose') }}</option>
                            <option value="digitalocean_app_platform">{{ __('DigitalOcean App Platform') }}</option>
                            <option value="aws_app_runner">{{ __('AWS App Runner') }}</option>
                        </select>
                    </div>
                </div>
                <div>
                    <x-input-label for="region" :value="__('Region')" />
                    <select id="region" wire:model="region" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm" required>
                        @foreach ($regions as $r)
                            <option value="{{ $r['slug'] }}">{{ $r['label'] }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('region')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="env_file_content" :value="__('Environment variables (optional)')" />
                    <textarea id="env_file_content" wire:model="env_file_content" rows="6" class="mt-1 block w-full rounded-md border-slate-300 font-mono text-sm shadow-sm" placeholder="APP_ENV=production&#10;LOG_LEVEL=info"></textarea>
                    <p class="mt-1 text-xs text-slate-500">{{ __('One KEY=value per line. Lines starting with # are ignored.') }}</p>
                </div>
            </div>
        </div>

        <div class="flex items-center justify-end gap-3">
            <a href="{{ route('sites.index') }}" wire:navigate class="text-sm font-medium text-slate-700 hover:text-slate-900">{{ __('Cancel') }}</a>
            <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="deploy">
                <span wire:loading.remove wire:target="deploy">{{ __('Deploy to dply edge') }}</span>
                <span wire:loading wire:target="deploy" class="inline-flex items-center justify-center gap-2">
                    <x-spinner variant="cream" />
                    {{ __('Deploying…') }}
                </span>
            </x-primary-button>
        </div>
    </form>
</div>
