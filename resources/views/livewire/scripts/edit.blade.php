<div>
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <nav class="text-sm text-brand-moss mb-6" aria-label="Breadcrumb">
            <ol class="flex flex-wrap items-center gap-2">
                <li><a href="{{ route('dashboard') }}" class="hover:text-brand-ink transition-colors" wire:navigate>{{ __('Dashboard') }}</a></li>
                <li class="text-brand-mist" aria-hidden="true">/</li>
                <li><a href="{{ route('scripts.index') }}" class="hover:text-brand-ink transition-colors" wire:navigate>{{ __('Scripts') }}</a></li>
                <li class="text-brand-mist" aria-hidden="true">/</li>
                <li class="text-brand-ink font-medium">{{ __('Edit script') }}</li>
            </ol>
        </nav>

        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between mb-8">
            <div>
                <h1 class="text-2xl font-semibold text-brand-ink">{{ __('Edit script') }}</h1>
                <p class="mt-2 text-sm text-brand-moss max-w-2xl leading-relaxed">
                    {{ __('Non-interactive scripts only. Save changes before running on servers.') }}
                </p>
            </div>
            @can('delete', $script)
                <button type="button" wire:click="deleteScript" wire:confirm="{{ __('Delete this script? Sites using it as a deploy script will stop referencing it.') }}" class="inline-flex items-center justify-center rounded-xl border border-red-200 bg-red-50 px-4 py-2.5 text-sm font-semibold text-red-800 hover:bg-red-100 self-start">
                    {{ __('Delete') }}
                </button>
            @endcan
        </div>

        @if ($flash_success)
            <div class="mb-6 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-900" role="status">{{ $flash_success }}</div>
        @endif

        <div class="space-y-8">
            <section class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden">
                <div class="grid md:grid-cols-12 gap-6 p-6 sm:p-8">
                    <div class="md:col-span-4">
                        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Script') }}</h2>
                        <p class="mt-2 text-sm text-brand-moss leading-relaxed">{{ __('Use non-interactive flags so execution does not hang over SSH.') }}</p>
                    </div>
                    <div class="md:col-span-8 space-y-5">
                        <div>
                            <x-input-label for="edit_script_name" :value="__('Label')" />
                            <x-text-input id="edit_script_name" wire:model="name" type="text" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('name')" class="mt-2" />
                        </div>
                        <label class="flex items-start gap-3 cursor-pointer">
                            <input type="checkbox" wire:model.boolean="use_as_default_for_new_sites" class="mt-1 rounded border-brand-ink/20 text-brand-ink focus:ring-brand-sage" />
                            <span class="text-sm text-brand-moss leading-relaxed">{{ __('Use this script as the default deploy script for new sites in this organization.') }}</span>
                        </label>
                        <div>
                            <x-input-label for="edit_run_as" :value="__('Run as user (optional)')" />
                            <x-text-input id="edit_run_as" wire:model="run_as_user" type="text" class="mt-1 block w-full font-mono text-sm" autocomplete="off" />
                            <x-input-error :messages="$errors->get('run_as_user')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="edit_content" :value="__('Content')" />
                            <textarea id="edit_content" wire:model="content" rows="18" class="mt-1 block w-full rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm font-mono shadow-sm focus:border-brand-sage focus:ring-brand-sage" spellcheck="false"></textarea>
                            <x-input-error :messages="$errors->get('content')" class="mt-2" />
                        </div>
                        <div class="flex flex-wrap justify-end gap-3">
                            <a href="{{ route('scripts.index') }}" wire:navigate class="inline-flex items-center rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink hover:bg-brand-sand/40">{{ __('Cancel') }}</a>
                            <x-primary-button type="button" wire:click="save">{{ __('Update') }}</x-primary-button>
                        </div>
                    </div>
                </div>
            </section>

            <section class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden">
                <div class="grid md:grid-cols-12 gap-6 p-6 sm:p-8">
                    <div class="md:col-span-4">
                        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Run script') }}</h2>
                        <p class="mt-2 text-sm text-brand-moss leading-relaxed">{{ __('Select servers to run this script on. Requires SSH access from Dply.') }}</p>
                    </div>
                    <div class="md:col-span-8 space-y-4">
                        @if ($servers->isEmpty())
                            <p class="text-sm text-brand-moss">{{ __('No servers in this organization yet.') }}</p>
                        @else
                            <div class="flex justify-end">
                                <button type="button" wire:click="toggleAllServers" class="text-xs font-medium text-brand-sage hover:text-brand-ink">{{ __('Toggle all') }}</button>
                            </div>
                            <div class="max-h-56 overflow-y-auto rounded-xl border border-brand-ink/10 divide-y divide-brand-ink/10">
                                @foreach ($servers as $server)
                                    <label class="flex items-center gap-3 px-3 py-2.5 hover:bg-brand-sand/30 cursor-pointer">
                                        <input type="checkbox" wire:model="selected_server_ids" value="{{ $server->id }}" class="rounded border-brand-ink/20 text-brand-ink focus:ring-brand-sage" />
                                        <span class="text-sm text-brand-ink font-medium">{{ $server->name }}</span>
                                        @if ($server->ip_address)
                                            <span class="text-xs text-brand-mist font-mono">{{ $server->ip_address }}</span>
                                        @endif
                                    </label>
                                @endforeach
                            </div>
                            <x-input-error :messages="$errors->get('selected_server_ids')" class="mt-2" />
                            <div class="flex justify-end">
                                <x-primary-button type="button" wire:click="runOnServers" wire:loading.attr="disabled">{{ __('Run script') }}</x-primary-button>
                            </div>
                        @endif

                        @if ($run_output !== null)
                            <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/20 p-4">
                                <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss mb-2">{{ __('Output') }}</p>
                                <pre class="text-xs font-mono text-brand-ink whitespace-pre-wrap break-words max-h-96 overflow-y-auto">{{ $run_output }}</pre>
                            </div>
                        @endif
                    </div>
                </div>
            </section>
        </div>
    </div>
</div>
