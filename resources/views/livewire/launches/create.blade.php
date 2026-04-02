<div>
    <div class="border-b border-slate-200 bg-white">
        <div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
            <a href="{{ route('dashboard') }}" wire:navigate class="text-sm font-medium text-sky-700 hover:text-sky-900">{{ __('← Back') }}</a>
            <h1 class="mt-4 text-3xl font-semibold tracking-tight text-slate-900">{{ __('Launch setup') }}</h1>
            <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-600">
                {{ __('Choose the deployment avenue first, then move into the setup flow for that path. BYO keeps real SSH-managed machines separate, containers now combine local Docker and remote Docker under one repo-first lane, and edge, cloud, and serverless each stay distinct on their own.') }}
            </p>
        </div>
    </div>

    <div class="py-10">
        <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
            <section aria-labelledby="launch-options-heading">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500">{{ __('Choose a path') }}</p>
                        <h2 id="launch-options-heading" class="mt-2 text-2xl font-semibold text-slate-900">{{ __('Start from the deployment model, not the provider card') }}</h2>
                    </div>
                    <a href="{{ route('docs.connect-provider') }}" wire:navigate class="text-sm font-medium text-sky-700 hover:text-sky-900">{{ __('Provider setup guide') }}</a>
                </div>

                @php
                    $launchLinks = [
                        __('Bring your own server') => route('servers.create'),
                        __('Containers') => route('launches.containers'),
                        __('Edge') => route('launches.edge-network'),
                        __('Cloud') => route('launches.cloud-network'),
                        __('Serverless') => route('launches.serverless'),
                    ];
                @endphp

                <div class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($launchOptions as $option)
                        <a href="{{ $launchLinks[$option['title']] }}" wire:navigate class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm transition hover:-translate-y-0.5 hover:border-sky-300 hover:shadow-md">
                            <h3 class="text-lg font-semibold text-slate-900">{{ $option['title'] }}</h3>
                            <p class="mt-3 text-sm leading-6 text-slate-600">{{ $option['description'] }}</p>
                            <p class="mt-4 text-sm font-medium text-sky-700">{{ __('Open path') }} →</p>
                        </a>
                    @endforeach
                </div>
            </section>
        </div>
    </div>
</div>
