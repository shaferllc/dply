<x-app-layout>
    <div class="mx-auto max-w-lg px-4 py-10 sm:px-6 lg:px-8">
        <h1 class="text-xl font-semibold text-brand-ink">{{ __('Shared database credentials') }}</h1>
        <p class="mt-2 text-sm text-brand-moss">
            {{ __('Server: :name', ['name' => $server->name]) }}
            <span class="text-brand-mist" aria-hidden="true">·</span>
            {{ __('Database: :db', ['db' => $database->name]) }}
        </p>
        <dl class="mt-8 space-y-4 rounded-2xl border border-brand-ink/10 bg-white p-6 text-sm shadow-sm">
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Username') }}</dt>
                <dd class="mt-1 font-mono text-brand-ink">{{ $database->username }}</dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Password') }}</dt>
                <dd class="mt-1 break-all font-mono text-brand-ink">{{ $database->password }}</dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Connection URL') }}</dt>
                <dd class="mt-1 break-all font-mono text-xs text-brand-moss">{{ $database->connectionUrl() }}</dd>
            </div>
        </dl>
        <p class="mt-6 text-xs text-brand-mist">
            {{ __('This page may stop working after the link expires or reaches its view limit. Do not share it publicly.') }}
        </p>
    </div>
</x-app-layout>
