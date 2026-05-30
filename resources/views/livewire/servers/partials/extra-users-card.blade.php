@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\ServerDatabase> $databases */
    $databases = $databases ?? collect();
    $engine = $engine ?? 'mysql';
    $engineLabel = $engineLabels[$engine] ?? ucfirst($engine);
    $selectedExtraDb = $databases->firstWhere('id', $extra_db_id ?? null);
@endphp
<div class="{{ $card ?? 'dply-card overflow-hidden' }} overflow-hidden">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
            <x-heroicon-o-users class="h-5 w-5" aria-hidden="true" />
        </span>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Users') }}</p>
            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __(':engine database users', ['engine' => $engineLabel]) }}</h2>
            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Each tracked database has a primary user created alongside it. Use the Actions menu on the database to copy connection details.') }}</p>
        </div>
    </div>
    <div class="px-6 py-6 sm:px-7">
    @if ($databases->isEmpty())
        <x-empty-state
            borderless
            icon="heroicon-o-users"
            tone="sage"
            :title="__('No :engine database users yet', ['engine' => $engineLabel])"
            :description="__('Add a database on Basics to provision a primary user on the server. Extra users can be added here afterward.')"
        >
            <x-slot:actions>
                <button
                    type="button"
                    wire:click="setWorkspaceTab('databases')"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-brand-forest/90"
                >
                    <x-heroicon-o-plus class="h-4 w-4" aria-hidden="true" />
                    {{ __('Go to Basics') }}
                </button>
            </x-slot:actions>
        </x-empty-state>
    @else
        <div class="mt-6 overflow-x-auto rounded-xl border border-brand-ink/10">
            <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                <thead class="bg-brand-sand/40 text-left text-xs font-semibold uppercase tracking-wide text-brand-mist">
                    <tr>
                        <th class="px-4 py-3">{{ __('User') }}</th>
                        <th class="px-4 py-3">{{ __('Database') }}</th>
                        <th class="px-4 py-3">{{ __('Host') }}</th>
                        <th class="px-4 py-3 text-end">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-ink/10 bg-white">
                    @foreach ($databases->sortBy('username') as $db)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-3 font-mono text-brand-ink">{{ $db->username }}</td>
                            <td class="whitespace-nowrap px-4 py-3 font-mono text-brand-ink">{{ $db->name }}</td>
                            <td class="px-4 py-3 font-mono text-xs text-brand-moss">{{ $db->host }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-end">
                                <button
                                    type="button"
                                    wire:click="openCredentialsModal(@js($db->id))"
                                    wire:loading.attr="disabled"
                                    wire:target="openCredentialsModal"
                                    class="text-xs font-medium text-brand-forest hover:underline"
                                >
                                    {{ __('Credentials') }}
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-10 border-t border-brand-ink/10 pt-8">
            <h3 class="text-base font-semibold text-brand-ink">{{ __('Extra database users') }}</h3>
            <p class="mt-2 text-sm text-brand-moss">{{ __('Create an additional user and grant access on the chosen database.') }}</p>
            <form wire:submit="addExtraMysqlUser" class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <x-input-label for="extra_db_id" value="{{ __('Database') }}" />
                    <select id="extra_db_id" wire:model.live="extra_db_id" wire:loading.attr="disabled" wire:target="addExtraMysqlUser" class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm">
                        <option value="">{{ __('Select…') }}</option>
                        @foreach ($databases as $edb)
                            <option value="{{ $edb->id }}">{{ $edb->name }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('extra_db_id')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="extra_username" value="{{ __('Username') }}" />
                    <x-text-input id="extra_username" wire:model="extra_username" wire:loading.attr="disabled" wire:target="addExtraMysqlUser" class="mt-1 block w-full font-mono text-sm" />
                    <x-input-error :messages="$errors->get('extra_username')" class="mt-1" />
                </div>
                <div>
                    <x-password-field
                        id="extra_password"
                        :label="__('Password')"
                        wire:model="extra_password"
                        wire:target="addExtraMysqlUser"
                    />
                    <x-input-error :messages="$errors->get('extra_password')" class="mt-1" />
                </div>
                @if ($engine !== 'postgres')
                    {{-- Postgres roles are global; the Livewire handler stamps 'localhost' for the column either way. --}}
                    <div class="sm:col-span-2">
                        <x-input-label for="extra_host" value="{{ __('Host') }}" />
                        <x-text-input id="extra_host" wire:model="extra_host" wire:loading.attr="disabled" wire:target="addExtraMysqlUser" class="mt-1 block w-full font-mono text-sm" />
                    </div>
                @endif
                <div class="sm:col-span-2 flex justify-end">
                    <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="addExtraMysqlUser">
                        <span wire:loading.remove wire:target="addExtraMysqlUser">{{ __('Add database user') }}</span>
                        <span wire:loading wire:target="addExtraMysqlUser">{{ __('Adding user…') }}</span>
                    </x-primary-button>
                </div>
            </form>
            @foreach ($databases as $edb)
                @if ($edb->extraUsers->isNotEmpty())
                    <ul class="mt-4 space-y-2 text-sm">
                        @foreach ($edb->extraUsers as $ex)
                            <li class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-brand-ink/10 px-3 py-2">
                                <span class="font-mono">
                                    @if ($edb->engine === 'postgres')
                                        {{ $ex->username }} → {{ $edb->name }}
                                    @else
                                        {{ $ex->username.'@'.$ex->host }} → {{ $edb->name }}
                                    @endif
                                </span>
                                <button type="button" wire:click="openConfirmActionModal('removeExtraUser', ['{{ $ex->id }}'], @js(__('Remove extra database user')), @js(__('Drop this user on the server and remove it from Dply?')), @js(__('Remove user')), true)" wire:loading.attr="disabled" wire:target="removeExtraUser" class="text-xs font-medium text-red-700 hover:underline">{{ __('Remove') }}</button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            @endforeach
        </div>
    @endif
    </div>
</div>
