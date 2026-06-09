@php
    /** @var \App\Models\ServerDatabase|null $database */
    $database = $database ?? null;
@endphp
<div class="{{ $card ?? 'dply-card overflow-hidden' }} p-6 sm:p-8">
    <h2 class="text-base font-semibold text-brand-ink">{{ __('Connection snippet') }}</h2>
    @if ($database === null)
        <x-empty-state
            class="mt-4"
            icon="heroicon-o-code-bracket"
            tone="sage"
            :title="__('No connection snippet yet')"
            :description="__('Create a database on Basics, then return here for a ready-to-paste .env block for apps on this server.')"
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
        <p class="mt-2 text-sm text-brand-moss">{{ __('Drop into your app .env for a tracked database — localhost apps on the same server.') }}</p>
        @switch($database->engine)
            @case('sqlite')
                <pre class="mt-4 overflow-x-auto rounded-xl border border-brand-ink/10 bg-zinc-50 p-4 font-mono text-xs text-brand-ink">DB_CONNECTION=sqlite
DB_DATABASE={{ $database->host }}</pre>
                @break
            @case('postgres')
                <pre class="mt-4 overflow-x-auto rounded-xl border border-brand-ink/10 bg-zinc-50 p-4 font-mono text-xs text-brand-ink">DB_CONNECTION=pgsql
DB_HOST={{ $database->host ?: '127.0.0.1' }}
DB_PORT={{ $database->defaultPort() }}
DB_DATABASE={{ $database->name }}
DB_USERNAME={{ $database->username }}
DB_PASSWORD=********</pre>
                @break
            @default
                <pre class="mt-4 overflow-x-auto rounded-xl border border-brand-ink/10 bg-zinc-50 p-4 font-mono text-xs text-brand-ink">DB_CONNECTION=mysql
DB_HOST={{ $database->host ?: '127.0.0.1' }}
DB_PORT={{ $database->defaultPort() }}
DB_DATABASE={{ $database->name }}
DB_USERNAME={{ $database->username }}
DB_PASSWORD=********</pre>
        @endswitch
    @endif
</div>
