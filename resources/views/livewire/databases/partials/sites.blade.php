<div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
    <div class="border-b border-slate-200 px-5 py-4">
        <h2 class="text-sm font-semibold text-slate-900">{{ __('Attached apps') }}</h2>
        <p class="mt-1 text-xs text-slate-600">{{ __('Each app gets this database\'s connection variables under its own prefix. Detaching strips exactly those keys on the next deploy.') }}</p>
    </div>

    @if ($database->sites->isEmpty())
        <p class="px-5 py-8 text-center text-sm text-slate-600">{{ __('Nothing is attached to this database yet.') }}</p>
    @else
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">
                <tr>
                    <th class="px-5 py-3">{{ __('App') }}</th>
                    <th class="px-5 py-3">{{ __('Env prefix') }}</th>
                    <th class="px-5 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 text-slate-800">
                @foreach ($database->sites as $site)
                    <tr>
                        <td class="px-5 py-3 font-medium text-slate-900">{{ $site->name }}</td>
                        <td class="px-5 py-3 font-mono text-xs text-slate-500">{{ $site->pivot->env_prefix ?: $database->defaultEnvPrefix() }}</td>
                        <td class="px-5 py-3 text-right">
                            <button type="button"
                                wire:click="detachSite('{{ $site->id }}')"
                                wire:confirm="{{ __('Detach :name? Its database connection variables are removed on the next deploy.', ['name' => $site->name]) }}"
                                class="text-xs font-medium text-rose-700 hover:text-rose-900">
                                {{ __('Detach') }}
                            </button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
