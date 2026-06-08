@props(['provider'])

@php
    $icon = match ($provider) {
        'digitalocean' => 'heroicon-o-cloud',
        'hetzner' => 'heroicon-o-server',
        'linode' => 'heroicon-o-circle-stack',
        'vultr' => 'heroicon-o-bolt',
        'cloudflare', 'gandi', 'namecheap', 'vercel_dns' => 'heroicon-o-globe-alt',
        'upcloud' => 'heroicon-o-cloud-arrow-up',
        'ovh' => 'heroicon-o-building-office-2',
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
