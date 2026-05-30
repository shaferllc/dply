<?php

declare(strict_types=1);

use App\Services\Servers\TraefikCustomRoutesConfig;

it('renders a custom traefik http router yaml', function (): void {
    $yaml = app(TraefikCustomRoutesConfig::class)->render('legacy-api', [
        'hosts' => 'api.example.com',
        'upstream' => '127.0.0.1:8080',
        'middlewares' => 'dply-custom-mw-strip',
    ]);

    expect($yaml)
        ->toContain('dply-custom-legacy-api')
        ->toContain('Host(`api.example.com`)')
        ->toContain('http://127.0.0.1:8080')
        ->toContain('dply-custom-mw-strip');
});
