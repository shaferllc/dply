@php
    /** @var \App\Models\ServerDatabase|null $database */
    $database = $database ?? null;

    // `host` on the row is the engine's LISTEN address, which for a remote-access
    // install is a wildcard (0.0.0.0 / ::). That is not a connectable address —
    // pasting DB_HOST=0.0.0.0 into an app's .env fails. This snippet is scoped to
    // "apps running on this server", so collapse any wildcard bind to loopback.
    $connectHost = static function (?string $host): string {
        $host = trim((string) $host);

        return in_array($host, ['', '0.0.0.0', '::', '[::]', '*'], true) ? '127.0.0.1' : $host;
    };
@endphp
<div class="{{ $card ?? 'dply-card overflow-hidden' }}">
    <x-workspace-panel-head
        dense
        icon="heroicon-o-code-bracket"
        :title="__('Connection snippet')"
        :note="__('Ready-to-paste .env block for apps running on this server.')"
        class="border-b border-brand-ink/10"
    />
    <div class="px-4 py-3.5 sm:px-5">
    @if ($database === null)
        <x-empty-state
            borderless
            compact
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
        @switch($database->engine)
            @case('sqlite')
                <pre class="overflow-x-auto rounded-xl border border-brand-ink/10 bg-zinc-50 p-4 font-mono text-xs text-brand-ink">DB_CONNECTION=sqlite
DB_DATABASE={{ $database->host }}</pre>
                @break
            @case('postgres')
                <pre class="overflow-x-auto rounded-xl border border-brand-ink/10 bg-zinc-50 p-4 font-mono text-xs text-brand-ink">DB_CONNECTION=pgsql
DB_HOST={{ $connectHost($database->host) }}
DB_PORT={{ $database->defaultPort() }}
DB_DATABASE={{ $database->name }}
DB_USERNAME={{ $database->username }}
DB_PASSWORD=********</pre>
                @break
            @case('clickhouse')
                {{-- ClickHouse is not a Laravel DB_CONNECTION driver — it speaks HTTP
                     on 8123 and is configured with its own env block. These names match
                     what dply's own client reads (config/servers/logs.php: 'clickhouse'),
                     so a snippet pasted here lines up with the Logs add-on. CLICKHOUSE_PORT
                     is included for third-party clients; dply's config pins http_port. --}}
                <pre class="overflow-x-auto rounded-xl border border-brand-ink/10 bg-zinc-50 p-4 font-mono text-xs text-brand-ink">CLICKHOUSE_HOST={{ $connectHost($database->host) }}
CLICKHOUSE_SCHEME=http
CLICKHOUSE_PORT={{ $database->defaultPort() }}
CLICKHOUSE_DATABASE={{ $database->name }}
CLICKHOUSE_USERNAME={{ $database->username }}
CLICKHOUSE_PASSWORD=********</pre>
                @break
            @default
                <pre class="overflow-x-auto rounded-xl border border-brand-ink/10 bg-zinc-50 p-4 font-mono text-xs text-brand-ink">DB_CONNECTION=mysql
DB_HOST={{ $connectHost($database->host) }}
DB_PORT={{ $database->defaultPort() }}
DB_DATABASE={{ $database->name }}
DB_USERNAME={{ $database->username }}
DB_PASSWORD=********</pre>
        @endswitch
    @endif
    </div>
</div>
