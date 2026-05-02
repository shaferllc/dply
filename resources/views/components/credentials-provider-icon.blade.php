@props(['provider'])

@php
    $iconAttrs = $attributes->merge([
        'class' => 'h-4 w-4 shrink-0 opacity-90',
        'aria-hidden' => 'true',
    ]);
@endphp

@switch($provider)
    @case('digitalocean')
        <x-heroicon-o-cloud {{ $iconAttrs }} />
        @break
    @case('hetzner')
        <x-heroicon-o-server {{ $iconAttrs }} />
        @break
    @case('linode')
    @case('akamai')
        <x-heroicon-o-circle-stack {{ $iconAttrs }} />
        @break
    @case('vultr')
        <x-heroicon-o-bolt {{ $iconAttrs }} />
        @break
    @case('cloudflare')
        <x-heroicon-o-globe-alt {{ $iconAttrs }} />
        @break
    @case('equinix_metal')
        <x-heroicon-o-cpu-chip {{ $iconAttrs }} />
        @break
    @case('upcloud')
        <x-heroicon-o-cloud-arrow-up {{ $iconAttrs }} />
        @break
    @case('scaleway')
        <x-heroicon-o-squares-2x2 {{ $iconAttrs }} />
        @break
    @case('ovh')
        <x-heroicon-o-building-office-2 {{ $iconAttrs }} />
        @break
    @case('rackspace')
        <x-heroicon-o-server {{ $iconAttrs }} />
        @break
    @case('fly_io')
        <x-heroicon-o-paper-airplane {{ $iconAttrs }} />
        @break
    @case('render')
        <x-heroicon-o-rocket-launch {{ $iconAttrs }} />
        @break
    @case('railway')
        <x-heroicon-o-arrow-path-rounded-square {{ $iconAttrs }} />
        @break
    @case('coolify')
        <x-heroicon-o-wrench-screwdriver {{ $iconAttrs }} />
        @break
    @case('cap_rover')
        <x-heroicon-o-command-line {{ $iconAttrs }} />
        @break
    @case('aws')
    @case('gcp')
    @case('azure')
    @case('oracle')
        <x-heroicon-o-cloud {{ $iconAttrs }} />
        @break
    @default
        <x-heroicon-o-server {{ $iconAttrs }} />
@endswitch
