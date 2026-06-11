<?php

declare(strict_types=1);

use Dply\NginxConfig\ConfigDiff;

$server = static fn (string $body): string => "server {\n".$body."\n}\n";

it('parses a standalone server snippet without context errors', function () use ($server) {
    $payload = ConfigDiff::parse($server('    listen 80;
    server_name example.com;
    location / { try_files $uri $uri/ /index.php?$query_string; }'));

    expect($payload['status'])->toBe('ok')
        ->and($payload['errors'])->toBe([]);
});

it('flattens directives into block-prefixed signatures', function () use ($server) {
    $sigs = ConfigDiff::signatures($server('    listen 80;
    location /api { proxy_pass http://127.0.0.1:9000; }'));

    expect($sigs)->toContain('server > listen 80')
        ->toContain('server > location /api')
        ->toContain('server > location /api > proxy_pass http://127.0.0.1:9000');
});

it('ignores comments when flattening', function () use ($server) {
    $sigs = ConfigDiff::signatures($server('    # a hand-written note
    listen 80;'));

    expect(collect($sigs)->every(fn ($s) => ! str_contains($s, '#')))->toBeTrue();
});

it('reports directives an overwrite would destroy', function () use ($server) {
    $current = $server('    listen 80;
    add_header X-Frame-Options SAMEORIGIN;
    location /legacy { proxy_pass http://127.0.0.1:9001; }');

    $incoming = $server('    listen 80;');

    $lost = ConfigDiff::lostOnOverwrite($current, $incoming);

    expect($lost)->toContain('server > add_header X-Frame-Options SAMEORIGIN')
        ->toContain('server > location /legacy')
        ->toContain('server > location /legacy > proxy_pass http://127.0.0.1:9001');
});

it('reports nothing lost when the incoming config is a structural superset', function () use ($server) {
    $current = $server('    listen 80;');
    $incoming = $server('    listen 80;
    server_name example.com;');

    expect(ConfigDiff::lostOnOverwrite($current, $incoming))->toBe([]);
});

it('reports nothing lost for identical configs', function () use ($server) {
    $config = $server('    listen 80;
    location / { try_files $uri $uri/ =404; }');

    expect(ConfigDiff::lostOnOverwrite($config, $config))->toBe([]);
});
