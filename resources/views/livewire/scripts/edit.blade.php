<div>
    <div class="dply-page-shell py-8">
        <nav class="text-sm text-brand-moss mb-6" aria-label="Breadcrumb">
            <ol class="flex flex-wrap items-center gap-2">
                <li><a href="{{ route('dashboard') }}" class="hover:text-brand-ink transition-colors" wire:navigate>{{ __('Dashboard') }}</a></li>
                <li class="text-brand-mist" aria-hidden="true">/</li>
                <li><a href="{{ route('scripts.index') }}" class="hover:text-brand-ink transition-colors" wire:navigate>{{ __('Scripts') }}</a></li>
                <li class="text-brand-mist" aria-hidden="true">/</li>
                <li class="text-brand-ink font-medium">{{ __('Edit script') }}</li>
            </ol>
        </nav>

        <x-page-header
            :title="__('Edit script')"
            :description="__('Non-interactive scripts only. Save changes before running on servers.')"
            doc-route="docs.index"
            flush
        >
            <x-slot name="actions">
                @can('delete', $script)
                    <button type="button" wire:click="openConfirmActionModal('deleteScript', [], @js(__('Delete script')), @js(__('Delete this script? Sites using it as a deploy script will stop referencing it.')), @js(__('Delete')), true)" class="inline-flex items-center justify-center rounded-xl border border-red-200 bg-red-50 px-4 py-2.5 text-sm font-semibold text-red-800 hover:bg-red-100">
                        {{ __('Delete') }}
                    </button>
                @endcan
            </x-slot>
        </x-page-header>

        <div class="space-y-8">
            <section class="dply-card overflow-hidden">
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
                        </div>
                    </div>
                </div>
            </section>

            <section class="dply-card overflow-hidden">
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
                                <x-primary-button type="button" wire:click="runOnServers" wire:loading.attr="disabled">
                                    <span wire:loading.remove wire:target="runOnServers">{{ __('Run script') }}</span>
                                    <span wire:loading wire:target="runOnServers" class="inline-flex items-center justify-center gap-2">
                                        <x-spinner variant="cream" size="sm" />
                                        {{ __('Running…') }}
                                    </span>
                                </x-primary-button>
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

    <x-unsaved-changes-bar
        :message="__('You have unsaved changes to this script.')"
        saveAction="save"
        discardAction="discardScriptFieldsUnsaved"
        targets="name,content,run_as_user,use_as_default_for_new_sites"
        :saveLabel="__('Save')"
    />

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
    </x-slot>
</div>
