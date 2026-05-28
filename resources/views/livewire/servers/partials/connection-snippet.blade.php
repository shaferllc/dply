@php
    /** @var \App\Models\ServerDatabase|null $database */
    $database = $database ?? null;
@endphp
<div class="{{ $card ?? 'dply-card overflow-hidden' }} p-6 sm:p-8">
    <h2 class="text-base font-semibold text-brand-ink">{{ __('Connection snippet') }}</h2>
    @if ($database === null)
        <p class="mt-2 text-sm text-brand-moss">{{ __('Add a database to see a ready-to-paste .env block here.') }}</p>
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
