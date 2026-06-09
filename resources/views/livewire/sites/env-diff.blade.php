<div class="mx-auto max-w-6xl px-6 py-10">
    <nav class="mb-4 text-sm text-slate-500">
        <a href="{{ route('sites.show', ['server' => $server, 'site' => $site]) }}" wire:navigate class="hover:text-slate-700">{{ $site->name }}</a>
        <span class="mx-2 text-slate-400">/</span>
        <span class="text-slate-700">{{ __('Env diff') }}</span>
    </nav>

    <header class="mb-6 border-b border-slate-200 pb-4">
        <h1 class="text-2xl font-semibold text-slate-900">{{ __('Cache vs server .env') }}</h1>
        <p class="mt-1 text-sm text-slate-600">{{ __('Compare Dply\'s encrypted cache for this site to the live .env on the server. Drift here is a sign the file has been edited out-of-band.') }}</p>
    </header>

    <div class="mb-6 flex flex-wrap items-center gap-3">
        <button type="button" wire:click="toggleReveal" class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">
            {{ $reveal ? __('Hide values') : __('Reveal values') }}
        </button>
        <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'environment']) }}" wire:navigate class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">
            {{ __('Back to environment settings') }}
        </a>
    </div>

    @if ($unsupported)
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            {{ __('This site\'s runtime does not have a server-side .env file (container or serverless). The cached env IS the source of truth — no diff to compute.') }}
        </div>
    @elseif (! $serverEnvLoaded)
        {{-- Reading the live .env is a synchronous SSH round-trip; defer it to wire:init so
             the page paints immediately, then the diff fills in. --}}
        <div wire:init="loadServerEnv" class="rounded-2xl border border-slate-200 bg-white p-6 text-center text-sm text-slate-600">
            <div class="flex items-center justify-center gap-3">
                <x-spinner variant="forest" size="sm" />
                {{ __('Reading the server .env over SSH…') }}
            </div>
        </div>
    @elseif ($serverError !== '')
        <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900">
            <p class="font-semibold">{{ __('Could not read .env from server.') }}</p>
            <p class="mt-1 font-mono text-xs">{{ $serverError }}</p>
        </div>
    @elseif ($inSync)
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-6 text-center text-sm text-emerald-900">
            <p class="text-base font-semibold">{{ __('Cache and server are in sync') }}</p>
            <p class="mt-1">{{ __('Every key matches.') }}</p>
        </div>
    @else
        <div class="grid gap-6 lg:grid-cols-2">
            @if ($onlyInCache !== [])
                <section class="rounded-2xl border border-rose-200 bg-rose-50/60 p-5 shadow-sm">
                    <h2 class="text-sm font-semibold text-rose-900">{{ __('Only in cache (not yet pushed)') }} <span class="text-xs font-normal text-rose-700">({{ count($onlyInCache) }})</span></h2>
                    <ul class="mt-3 space-y-1 font-mono text-xs">
                        @foreach ($onlyInCache as $key)
                            <li class="rounded bg-white px-2 py-1 text-slate-800">- {{ $key }}</li>
                        @endforeach
                    </ul>
                </section>
            @endif
            @if ($onlyInServer !== [])
                <section class="rounded-2xl border border-emerald-200 bg-emerald-50/60 p-5 shadow-sm">
                    <h2 class="text-sm font-semibold text-emerald-900">{{ __('Only on server (drift)') }} <span class="text-xs font-normal text-emerald-700">({{ count($onlyInServer) }})</span></h2>
                    <ul class="mt-3 space-y-1 font-mono text-xs">
                        @foreach ($onlyInServer as $key)
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
                                <th class="px-3 py-2">{{ __('Cache') }}</th>
                                <th class="px-3 py-2">{{ __('Server') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 font-mono">
                            @foreach ($differs as $key => $pair)
                                <tr>
                                    <td class="px-3 py-2 font-medium text-slate-800">{{ $key }}</td>
                                    <td class="px-3 py-2 text-slate-700">{{ $pair['cache'] }}</td>
                                    <td class="px-3 py-2 text-slate-700">{{ $pair['server'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    @endif

    <x-cli-snippet class="mt-8" :command="'dply sites:env:diff '.$site->slug" />
</div>
