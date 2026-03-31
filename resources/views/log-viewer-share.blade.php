<x-app-layout>
    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <h1 class="text-2xl font-bold tracking-tight text-brand-ink">{{ __('Shared log snapshot') }}</h1>
        <p class="mt-2 text-sm text-brand-moss">
            {{ __('Server: :name', ['name' => $share->server->name]) }}
            <span class="text-brand-mist" aria-hidden="true">·</span>
            {{ __('Source: :key', ['key' => $share->log_key]) }}
            <span class="text-brand-mist" aria-hidden="true">·</span>
            {{ __('Expires: :time', ['time' => $share->expires_at->timezone(config('app.timezone'))->format('Y-m-d H:i T')]) }}
        </p>
        <pre
            class="mt-6 max-h-[70vh] overflow-x-auto overflow-y-auto whitespace-pre-wrap break-words rounded-xl border border-brand-ink/10 bg-zinc-50 p-4 font-mono text-xs text-brand-ink"
            role="log"
        >{{ $share->content }}</pre>
        <p class="mt-4 text-xs text-brand-mist">
            {{ __('This is a point-in-time snapshot. Open the server workspace log viewer for live data.') }}
        </p>
    </div>
</x-app-layout>
