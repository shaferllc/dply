@props(['provider'])

@php
    $icon = match ($provider) {
        'digitalocean' => 'heroicon-o-cloud',
        'hetzner' => 'heroicon-o-server',
        'linode', 'akamai' => 'heroicon-o-circle-stack',
        'vultr' => 'heroicon-o-bolt',
        'cloudflare', 'gandi', 'namecheap', 'vercel_dns' => 'heroicon-o-globe-alt',
        'equinix_metal' => 'heroicon-o-cpu-chip',
        'upcloud' => 'heroicon-o-cloud-arrow-up',
        'scaleway' => 'heroicon-o-squares-2x2',
        'ovh' => 'heroicon-o-building-office-2',
        'rackspace' => 'heroicon-o-server',
        'fly_io' => 'heroicon-o-paper-airplane',
        'render' => 'heroicon-o-rocket-launch',
        'railway' => 'heroicon-o-arrow-path-rounded-square',
        'coolify' => 'heroicon-o-wrench-screwdriver',
        'cap_rover' => 'heroicon-o-command-line',
        'aws', 'gcp', 'azure', 'oracle' => 'heroicon-o-cloud',
        'ploi', 'forge' => 'heroicon-o-arrow-down-tray',
        default => 'heroicon-o-server',
    };
@endphp

<x-dynamic-component
    :component="$icon"
    {{ $attributes->class('h-4 w-4 shrink-0 opacity-90') }}
    aria-hidden="true"
/>
