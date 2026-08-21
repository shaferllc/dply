@php
    use App\Modules\Database\Backends\DatabaseBackend;
@endphp

@if (! $capabilities[DatabaseBackend::CAP_USERS])
    @include('livewire.cloud.partials.database._unavailable', [
        'title' => __('Users are not managed here'),
        'reason' => $database->engine === \App\Models\CloudDatabase::ENGINE_REDIS
            ? __('Valkey authenticates with a single cluster credential — there are no additional users to create.')
            : __('This database\'s backend does not expose a user API to dply. Manage logins with your usual client, connected as the admin user.'),
    ])
@else
    <div class="space-y-6">
        @if ($revealedSecret !== null)
            <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <p class="font-semibold">{{ __('Password for :name', ['name' => $revealedSecretFor]) }}</p>
                        <p class="mt-1 break-all font-mono text-xs">{{ $revealedSecret }}</p>
                        <p class="mt-2 text-xs">{{ __('Shown once. The provider will not return it again.') }}</p>
                    </div>
                    <button type="button" wire:click="dismissSecret" class="shrink-0 text-xs font-medium text-amber-900 underline">
                        {{ __('Dismiss') }}
                    </button>
                </div>
            </div>
        @endif

        <form wire:submit="createUser" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <label for="new-user-name" class="text-sm font-semibold text-slate-900">{{ __('Add a user') }}</label>
            <p class="mt-1 text-xs text-slate-600">
                {{ __('The provider generates the password and returns it once. dply does not grant or revoke table privileges — do that with SQL after connecting.') }}
            </p>
            <div class="mt-3 flex flex-wrap gap-3">
                <input id="new-user-name" type="text" wire:model="newUserName"
                    placeholder="{{ __('reporting') }}"
                    class="min-w-0 flex-1 rounded-xl border-slate-300 text-sm shadow-sm focus:border-slate-900 focus:ring-slate-900" />
                <button type="submit" class="inline-flex items-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">
                    {{ __('Create user') }}
                </button>
            </div>
        </form>

        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            @if ($users === [])
                <p class="px-5 py-8 text-center text-sm text-slate-600">{{ __('No users reported by the provider.') }}</p>
            @else
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">
                        <tr>
                            <th class="px-5 py-3">{{ __('User') }}</th>
                            <th class="px-5 py-3">{{ __('Role') }}</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-slate-800">
                        @foreach ($users as $user)
                            @php $isAdmin = $adminUsername !== '' && strcasecmp($adminUsername, $user['name']) === 0; @endphp
                            <tr>
                                <td class="px-5 py-3 font-mono text-xs text-slate-900">
                                    {{ $user['name'] }}
                                    @if ($isAdmin)
                                        <span class="ml-2 rounded-full bg-slate-100 px-2 py-0.5 text-2xs font-semibold uppercase tracking-[0.14em] text-slate-600">{{ __('Admin') }}</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-slate-600">{{ $user['role'] }}</td>
                                <td class="px-5 py-3 text-right">
                                    <div class="flex justify-end gap-4">
                                        <button type="button"
                                            wire:click="rotatePassword('{{ $user['name'] }}')"
                                            wire:confirm="{{ $isAdmin
                                                ? __('Rotate the admin password? Every attached app keeps the old one until you re-attach it.')
                                                : __('Rotate the password for :name?', ['name' => $user['name']]) }}"
                                            class="text-xs font-medium text-slate-600 hover:text-slate-900">
                                            {{ __('Rotate password') }}
                                        </button>
                                        @unless ($isAdmin)
                                            <button type="button"
                                                wire:click="deleteUser('{{ $user['name'] }}')"
                                                wire:confirm="{{ __('Delete :name? Anything connecting as this user stops working immediately.', ['name' => $user['name']]) }}"
                                                class="text-xs font-medium text-rose-700 hover:text-rose-900">
                                                {{ __('Delete') }}
                                            </button>
                                        @endunless
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
@endif
