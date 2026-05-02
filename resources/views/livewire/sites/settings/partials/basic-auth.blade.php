<section class="space-y-6">
    <div class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-8">
        <h2 class="text-lg font-semibold text-brand-ink">{{ __('HTTP basic authentication') }}</h2>
        <p class="mt-1 text-sm text-brand-moss">
            {{ __('Add username and password pairs to protect all or part of this site. Dply updates nginx and Apache vhosts and writes htpasswd files on the server under your site repository’s .dply directory. Other web server types still receive the password files when you re-provision.') }}
        </p>

        @if (! $site->supportsBasicAuthProvisioning())
            <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50/80 px-4 py-3 text-sm text-amber-900">
                {{ __('Basic authentication applies to VM sites with managed web server configuration. Container and serverless runtimes use their own access controls.') }}
            </div>
        @else
            @if ($site->basicAuthUsers->isEmpty())
                <div class="mt-4 rounded-xl border border-sky-200 bg-sky-50/80 px-4 py-3 text-sm text-sky-900">
                    {{ __('You do not have any basic authentication users yet.') }}
                </div>
            @else
                <ul class="mt-4 divide-y divide-brand-ink/10 rounded-xl border border-brand-ink/10">
                    @foreach ($site->basicAuthUsers as $authUser)
                        <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                            <div class="min-w-0">
                                <p class="font-mono text-sm text-brand-ink">{{ $authUser->username }}</p>
                                <p class="mt-1 text-xs text-brand-moss">
                                    {{ __('Path') }}: <span class="font-mono">{{ $authUser->normalizedPath() }}</span>
                                </p>
                            </div>
                            <button
                                type="button"
                                wire:click="removeBasicAuthUser('{{ $authUser->id }}')"
                                class="text-sm font-medium text-red-700 hover:underline"
                            >
                                {{ __('Remove') }}
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif

            <form wire:submit="addBasicAuthUser" class="mt-6 grid gap-4 md:grid-cols-2">
                <div class="md:col-span-1">
                    <x-input-label for="new_basic_auth_username" :value="__('Name')" />
                    <x-text-input
                        id="new_basic_auth_username"
                        wire:model="new_basic_auth_username"
                        class="mt-1 block w-full font-mono text-sm"
                        autocomplete="off"
                    />
                    <x-input-error :messages="$errors->get('new_basic_auth_username')" class="mt-1" />
                </div>
                <div class="md:col-span-1">
                    <div class="flex items-end justify-between gap-2">
                        <x-input-label for="new_basic_auth_password" :value="__('Password')" class="flex-1" />
                        <button type="button" wire:click="generateBasicAuthPassword" class="text-xs font-medium text-brand-sage hover:underline">
                            {{ __('Generate') }}
                        </button>
                    </div>
                    <x-text-input
                        id="new_basic_auth_password"
                        wire:model="new_basic_auth_password"
                        type="password"
                        class="mt-1 block w-full font-mono text-sm"
                        autocomplete="new-password"
                    />
                    <x-input-error :messages="$errors->get('new_basic_auth_password')" class="mt-1" />
                </div>
                <div class="md:col-span-2">
                    <x-input-label for="new_basic_auth_path" :value="__('Path')" />
                    <x-text-input
                        id="new_basic_auth_path"
                        wire:model="new_basic_auth_path"
                        class="mt-1 block w-full font-mono text-sm"
                        placeholder="/"
                    />
                    <p class="mt-1 text-xs text-brand-moss">
                        {{ __('Use / for the whole site, or a prefix such as /wp-admin. Octane and Node sites support / only in this release.') }}
                    </p>
                    <x-input-error :messages="$errors->get('new_basic_auth_path')" class="mt-1" />
                </div>
                <div class="md:col-span-2 flex flex-wrap justify-end gap-3">
                    <x-primary-button type="submit">{{ __('Save') }}</x-primary-button>
                </div>
            </form>
        @endif
    </div>
</section>
