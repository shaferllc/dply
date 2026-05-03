<div class="mx-auto max-w-6xl px-6 py-10">
    <nav class="mb-4 text-sm text-slate-500">
        <a href="{{ route('sites.show', ['server' => $server, 'site' => $site]) }}" wire:navigate class="hover:text-slate-700">{{ $site->name }}</a>
        <span class="mx-2 text-slate-400">/</span>
        <span class="text-slate-700">{{ __('Env diff') }}</span>
    </nav>

    <header class="mb-6 border-b border-slate-200 pb-4">
        <h1 class="text-2xl font-semibold text-slate-900">{{ __('Environment diff') }}</h1>
        <p class="mt-1 text-sm text-slate-600">{{ __('Compare environment variables between two scopes for this site.') }}</p>
    </header>

    <div class="mb-6 flex flex-wrap items-end gap-3">
        <div>
            <label for="from_env" class="block text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('From') }}</label>
            <select id="from_env" wire:model.live="fromEnv" class="mt-1 rounded-md border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500">
                @foreach (array_unique(array_merge($availableEnvs, ['production', 'staging'])) as $env)
                    <option value="{{ $env }}">{{ $env }}</option>
                @endforeach
            </select>
        </div>
        <span class="self-center text-slate-400">↔</span>
        <div>
            <label for="to_env" class="block text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('To') }}</label>
            <select id="to_env" wire:model.live="toEnv" class="mt-1 rounded-md border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500">
                @foreach (array_unique(array_merge($availableEnvs, ['production', 'staging'])) as $env)
                    <option value="{{ $env }}">{{ $env }}</option>
                @endforeach
            </select>
        </div>
        <button type="button" wire:click="toggleReveal" class="self-end rounded-md border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">
            {{ $reveal ? __('Hide values') : __('Reveal values') }}
        </button>
    </div>

    @if ($fromEnv === $toEnv)
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            {{ __('From and To are the same environment — pick two different scopes to see a diff.') }}
        </div>
    @elseif ($inSync)
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-6 text-center text-sm text-emerald-900">
            <p class="text-base font-semibold">{{ __('Environments are in sync') }}</p>
            <p class="mt-1">{{ __('Every key matches between :from and :to.', ['from' => $fromEnv, 'to' => $toEnv]) }}</p>
        </div>
    @else
        <div class="grid gap-6 lg:grid-cols-2">
            @if ($onlyInFrom !== [])
                <section class="rounded-2xl border border-rose-200 bg-rose-50/60 p-5 shadow-sm">
                    <h2 class="text-sm font-semibold text-rose-900">{{ __('Only in :env', ['env' => $fromEnv]) }} <span class="text-xs font-normal text-rose-700">({{ count($onlyInFrom) }})</span></h2>
                    <ul class="mt-3 space-y-1 font-mono text-xs">
                        @foreach ($onlyInFrom as $key)
                            <li class="rounded bg-white px-2 py-1 text-slate-800">- {{ $key }}</li>
                        @endforeach
                    </ul>
                </section>
            @endif
            @if ($onlyInTo !== [])
                <section class="rounded-2xl border border-emerald-200 bg-emerald-50/60 p-5 shadow-sm">
                    <h2 class="text-sm font-semibold text-emerald-900">{{ __('Only in :env', ['env' => $toEnv]) }} <span class="text-xs font-normal text-emerald-700">({{ count($onlyInTo) }})</span></h2>
                    <ul class="mt-3 space-y-1 font-mono text-xs">
                        @foreach ($onlyInTo as $key)
                            <li class="rounded bg-white px-2 py-1 text-slate-800">+ {{ $key }}</li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>

        @if ($differs !== [])
            <section class="mt-6 rounded-2xl border border-amber-200 bg-amber-50/60 p-5 shadow-sm">
                <h2 class="text-sm font-semibold text-amber-900">{{ __('Differs in value') }} <span class="text-xs font-normal text-amber-700">({{ count($differs) }})</span></h2>
                <div class="mt-3 overflow-x-auto rounded-xl border border-slate-200 bg-white">
                    <table class="min-w-full divide-y divide-slate-200 text-xs">
                        <thead class="bg-slate-50 text-left font-semibold uppercase tracking-[0.12em] text-slate-500">
                            <tr>
                                <th class="px-3 py-2">{{ __('Key') }}</th>
                                <th class="px-3 py-2">{{ $fromEnv }}</th>
                                <th class="px-3 py-2">{{ $toEnv }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 font-mono">
                            @foreach ($differs as $key => $pair)
                                <tr>
                                    <td class="px-3 py-2 font-medium text-slate-800">{{ $key }}</td>
                                    <td class="px-3 py-2 text-slate-700">{{ $pair['from'] }}</td>
                                    <td class="px-3 py-2 text-slate-700">{{ $pair['to'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    @endif

    <footer class="mt-8 text-xs text-slate-500">
        {{ __('Same data is available from the terminal:') }}
        <code class="ml-1 select-all rounded bg-slate-100 px-1 py-0.5 font-mono">dply:site:env-diff {{ $site->slug }} --from={{ $fromEnv }} --to={{ $toEnv }}</code>
    </footer>
</div>
