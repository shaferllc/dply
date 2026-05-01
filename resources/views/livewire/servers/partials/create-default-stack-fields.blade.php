<div class="rounded-2xl border border-slate-200 p-4">
    <h4 class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('4. Default stack') }}</h4>
    <div class="mt-3 grid gap-4 sm:grid-cols-2">
        <div>
            <x-input-label for="webserver" :value="__('Web server')" />
            <select id="webserver" wire:model.live="form.webserver" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-sky-500 focus:ring-sky-500">
                @foreach ($provisionOptions['webservers'] as $option)
                    <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                @endforeach
            </select>
            <x-input-error :messages="$errors->get('webserver')" class="mt-1" />
        </div>

        <div>
            <x-input-label for="php_version" :value="__('PHP version')" />
            <select id="php_version" wire:model.live="form.php_version" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-sky-500 focus:ring-sky-500">
                @foreach ($provisionOptions['php_versions'] as $option)
                    <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                @endforeach
            </select>
            <x-input-error :messages="$errors->get('php_version')" class="mt-1" />
        </div>

        <div>
            <x-input-label for="database" :value="__('Database')" />
            <select id="database" wire:model.live="form.database" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-sky-500 focus:ring-sky-500">
                @foreach ($provisionOptions['databases'] as $option)
                    <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                @endforeach
            </select>
            <x-input-error :messages="$errors->get('database')" class="mt-1" />
        </div>

        <div>
            <x-input-label for="cache_service" :value="__('Cache service')" />
            <select id="cache_service" wire:model.live="form.cache_service" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-sky-500 focus:ring-sky-500">
                @foreach ($provisionOptions['cache_services'] as $option)
                    <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                @endforeach
            </select>
            <x-input-error :messages="$errors->get('cache_service')" class="mt-1" />
        </div>
    </div>

    @if ($selectedWebserver)
        <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50/80 p-4">
            <p class="text-sm font-semibold text-slate-900">{{ $selectedWebserver['label'] }}</p>
            <p class="mt-1 text-sm text-slate-600">{{ $selectedWebserver['summary'] ?? $selectedWebserver['detail'] ?? __('Selected as the default web server for this machine.') }}</p>
        </div>
    @endif
</div>
