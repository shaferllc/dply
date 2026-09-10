@props([
    'user' => '',
    'host' => '',
    'port' => '22',
])

@php
    // Built here, never interpolated in a template: `@` immediately before `{{`
    // is Blade's own escape directive and would emit literal braces.
    $uri = 'sftp://'.$user.'@'.$host.':'.$port;
@endphp

{{--
    A connection string whose parts are individually copyable.

    An SFTP client asks for host, username and port in separate fields, so
    offering only the whole URI means the operator copies it and then hand-edits
    it three times. Each segment is its own button; the trailing button takes the
    lot, for clients that accept a pasted sftp:// URI.
--}}
<div
    x-data="{ copied: null, copy(what, value) { navigator.clipboard.writeText(value); this.copied = what; setTimeout(() => this.copied = null, 1200) } }"
    {{ $attributes->class(['inline-flex max-w-full flex-wrap items-center rounded-md bg-brand-sand/70 px-1 py-0.5 font-mono text-xs ring-1 ring-inset ring-brand-ink/10']) }}
>
    <span class="select-none px-1 text-brand-mist">sftp://</span>

    <button
        type="button"
        class="rounded px-1 py-0.5 text-brand-ink hover:bg-white"
        :class="copied === 'user' && 'bg-emerald-100 text-emerald-800'"
        title="{{ __('Copy username') }}"
        @click="copy('user', @js($user))"
    >{{ $user }}</button>

    <span class="select-none text-brand-mist">&#64;</span>

    <button
        type="button"
        class="max-w-full truncate rounded px-1 py-0.5 text-brand-ink hover:bg-white"
        :class="copied === 'host' && 'bg-emerald-100 text-emerald-800'"
        title="{{ __('Copy host') }}"
        @click="copy('host', @js($host))"
    >{{ $host }}</button>

    <span class="select-none text-brand-mist">:</span>

    <button
        type="button"
        class="rounded px-1 py-0.5 text-brand-ink hover:bg-white"
        :class="copied === 'port' && 'bg-emerald-100 text-emerald-800'"
        title="{{ __('Copy port') }}"
        @click="copy('port', @js((string) $port))"
    >{{ $port }}</button>

    <button
        type="button"
        class="ml-1 inline-flex items-center justify-center rounded p-1 text-brand-mist hover:bg-white hover:text-brand-ink"
        title="{{ __('Copy the whole connection string') }}"
        aria-label="{{ __('Copy the whole connection string') }}"
        @click="copy('all', @js($uri))"
    >
        <x-heroicon-o-clipboard class="h-3.5 w-3.5" x-show="copied !== 'all'" />
        <x-heroicon-o-check class="h-3.5 w-3.5 text-emerald-700" x-show="copied === 'all'" x-cloak />
    </button>
</div>
