@php
    /** @var \App\Models\ServerDatabase|null $database */
    $database = $database ?? null;
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
DB_HOST={{ $database->host ?: '127.0.0.1' }}
DB_PORT={{ $database->defaultPort() }}
DB_DATABASE={{ $database->name }}
DB_USERNAME={{ $database->username }}
DB_PASSWORD=********</pre>
                @break
            @default
                <pre class="overflow-x-auto rounded-xl border border-brand-ink/10 bg-zinc-50 p-4 font-mono text-xs text-brand-ink">DB_CONNECTION=mysql
DB_HOST={{ $database->host ?: '127.0.0.1' }}
DB_PORT={{ $database->defaultPort() }}
DB_DATABASE={{ $database->name }}
DB_USERNAME={{ $database->username }}
DB_PASSWORD=********</pre>
        @endswitch
    @endif
    </div>
</div>
