<div>
    <x-livewire-validation-errors />

    <nav class="text-sm text-brand-moss mb-6" aria-label="Breadcrumb">
        <ol class="flex flex-wrap items-center gap-2">
            <li><a href="{{ route('dashboard') }}" class="hover:text-brand-ink transition-colors">{{ __('Dashboard') }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li><a href="{{ route('profile.edit') }}" class="hover:text-brand-ink transition-colors" wire:navigate>{{ __('Profile') }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li class="text-brand-ink font-medium">{{ __('Backup configurations') }}</li>
        </ol>
    </nav>

    <header class="mb-8">
        <h1 class="text-2xl font-semibold text-brand-ink">{{ __('Backup configurations') }}</h1>
        <p class="mt-2 text-sm text-brand-moss max-w-2xl leading-relaxed">
            {{ __('Create storage destinations for database or file backups. Credentials are encrypted at rest.') }}
        </p>
    </header>

    @if ($flash_success)
        <div class="mb-6 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-900" role="status">{{ $flash_success }}</div>
    @endif
    @if ($flash_error)
        <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900" role="alert">{{ $flash_error }}</div>
    @endif

    <div class="space-y-10">
        <section class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden">
            <div class="grid lg:grid-cols-12 gap-8 p-6 sm:p-8">
                <div class="lg:col-span-4">
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Create backup configuration') }}</h2>
                    <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                        {{ __('Choose a storage provider and enter connection details. You can reuse these when configuring backups on servers or sites.') }}
                    </p>
                </div>
                <div class="lg:col-span-8 space-y-5">
                    <div>
                        <x-input-label for="bc_create_name" :value="__('Name')" />
                        <x-text-input id="bc_create_name" wire:model="createForm.name" type="text" class="mt-1 block w-full" placeholder="{{ __('e.g. Production S3') }}" autocomplete="off" />
                        <x-input-error :messages="$errors->get('createForm.name')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="bc_create_provider" :value="__('Storage provider')" />
                        <select id="bc_create_provider" wire:model.live="createForm.provider" class="mt-1 block w-full rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage">
                            @foreach (\App\Models\BackupConfiguration::providers() as $p)
                                <option value="{{ $p }}">{{ \App\Models\BackupConfiguration::labelForProvider($p) }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('createForm.provider')" class="mt-2" />
                    </div>

                    @include('livewire.settings.partials.backup-provider-fields', ['formKey' => 'createForm', 'form' => $createForm])

                    <div class="flex justify-end pt-2">
                        <x-primary-button type="button" wire:click="createConfiguration" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="createConfiguration">{{ __('Create') }}</span>
                            <span wire:loading wire:target="createConfiguration" class="inline-flex items-center justify-center gap-2">
                                <x-spinner variant="cream" size="sm" />
                                {{ __('Creating…') }}
                            </span>
                        </x-primary-button>
                    </div>
                </div>
            </div>
        </section>

        @if ($editing_id)
            <section class="rounded-2xl border border-brand-sage/40 bg-brand-sand/20 shadow-sm overflow-hidden" wire:key="edit-{{ $editing_id }}">
                <div class="grid lg:grid-cols-12 gap-8 p-6 sm:p-8">
                    <div class="lg:col-span-4">
                        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Edit backup configuration') }}</h2>
                        <p class="mt-2 text-sm text-brand-moss leading-relaxed">{{ __('Update the label or credentials, then save.') }}</p>
                    </div>
                    <div class="lg:col-span-8 space-y-5">
                        <div>
                            <x-input-label for="bc_edit_name" :value="__('Name')" />
                            <x-text-input id="bc_edit_name" wire:model="editForm.name" type="text" class="mt-1 block w-full" autocomplete="off" />
                            <x-input-error :messages="$errors->get('editForm.name')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="bc_edit_provider" :value="__('Storage provider')" />
                            <select id="bc_edit_provider" wire:model.live="editForm.provider" class="mt-1 block w-full rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage">
                                @foreach (\App\Models\BackupConfiguration::providers() as $p)
                                    <option value="{{ $p }}">{{ \App\Models\BackupConfiguration::labelForProvider($p) }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('editForm.provider')" class="mt-2" />
                        </div>

                        @include('livewire.settings.partials.backup-provider-fields', ['formKey' => 'editForm', 'form' => $editForm])

                        <div class="flex flex-wrap justify-end gap-3 pt-2">
                            <x-secondary-button type="button" wire:click="cancelEdit">{{ __('Cancel') }}</x-secondary-button>
                            <x-primary-button type="button" wire:click="updateConfiguration" wire:loading.attr="disabled">
                                <span wire:loading.remove wire:target="updateConfiguration">{{ __('Save changes') }}</span>
                                <span wire:loading wire:target="updateConfiguration" class="inline-flex items-center justify-center gap-2">
                                    <x-spinner variant="cream" size="sm" />
                                    {{ __('Saving…') }}
                                </span>
                            </x-primary-button>
                        </div>
                    </div>
                </div>
            </section>
        @endif

        <section class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden">
            <div class="p-6 sm:p-8">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between mb-6">
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Your configurations') }}</h2>
                    <div class="w-full sm:max-w-xs">
                        <label for="bc_search" class="sr-only">{{ __('Search') }}</label>
                        <x-text-input id="bc_search" wire:model.live.debounce.300ms="search" type="search" class="block w-full" placeholder="{{ __('Search by name…') }}" autocomplete="off" />
                    </div>
                </div>

                @if ($configurations->isEmpty())
                    <p class="text-sm text-brand-moss py-8 text-center border border-dashed border-brand-ink/15 rounded-xl">{{ __('No backup configurations yet.') }}</p>
                @else
                    <div class="overflow-x-auto rounded-xl border border-brand-ink/10">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="border-b border-brand-ink/10 bg-brand-sand/40 text-left text-xs font-semibold uppercase tracking-wide text-brand-moss">
                                    <th class="px-4 py-3">{{ __('Name') }}</th>
                                    <th class="px-4 py-3">{{ __('Provider') }}</th>
                                    <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-brand-ink/10">
                                @foreach ($configurations as $row)
                                    <tr wire:key="bc-{{ $row->id }}" class="hover:bg-brand-sand/20">
                                        <td class="px-4 py-3 font-medium text-brand-ink">{{ $row->name }}</td>
                                        <td class="px-4 py-3 text-brand-moss">{{ \App\Models\BackupConfiguration::labelForProvider($row->provider) }}</td>
                                        <td class="px-4 py-3 text-right whitespace-nowrap">
                                            <button type="button" wire:click="startEdit({{ $row->id }})" class="text-sm font-medium text-brand-sage hover:text-brand-ink mr-4">{{ __('Edit') }}</button>
                                            <button type="button" wire:click="openConfirmActionModal('deleteConfiguration', [{{ $row->id }}], @js(__('Delete backup configuration')), @js(__('Remove this backup configuration?')), @js(__('Delete')), true)" class="text-sm font-medium text-red-700 hover:text-red-900">{{ __('Delete') }}</button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </section>
    </div>

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
    </x-slot>
</div>
