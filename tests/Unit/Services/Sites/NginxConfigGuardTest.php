<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Sites\NginxConfigGuardTest;

use App\Services\Sites\NginxConfigGuard;

beforeEach(fn () => $this->guard = new NginxConfigGuard);

$server = static fn (string $body): string => "server {\n".$body."\n}\n";

test('stamp adds the ownership marker and is idempotent', function () use ($server) {
    $config = $server('    listen 80;');

    $stamped = $this->guard->stamp($config, 'example.com');

    expect($this->guard->isManaged($stamped))->toBeTrue()
        ->and($this->guard->isManaged($config))->toBeFalse()
        ->and($this->guard->stamp($stamped, 'example.com'))->toBe($stamped);
});

test('foreignDirectives lists hand-added directives an overwrite would remove', function () use ($server) {
    $current = $server('    listen 80;
    add_header X-Custom hi;
    location /internal { allow 10.0.0.0/8; deny all; }');

    $incoming = $this->guard->stamp($server('    listen 80;'), 'example.com');

    $foreign = $this->guard->foreignDirectives($current, $incoming);

    expect($foreign)->toContain('server > add_header X-Custom hi')
        ->toContain('server > location /internal');
});

test('the ownership marker comment is not treated as a foreign directive', function () use ($server) {
    $config = $server('    listen 80;');
    $stamped = $this->guard->stamp($config, 'example.com');

    expect($this->guard->foreignDirectives($stamped, $stamped))->toBe([]);
});

test('an empty or null current config has nothing to protect', function () use ($server) {
    $incoming = $server('    listen 80;');

    expect($this->guard->foreignDirectives(null, $incoming))->toBe([])
        ->and($this->guard->foreignDirectives('', $incoming))->toBe([]);
});

test('mode falls back to warn for an unknown configured value', function () {
    config()->set('dply.nginx_overwrite_guard', 'nonsense');
    expect($this->guard->mode())->toBe(NginxConfigGuard::MODE_WARN);

    config()->set('dply.nginx_overwrite_guard', 'abort');
    expect($this->guard->mode())->toBe(NginxConfigGuard::MODE_ABORT);

    config()->set('dply.nginx_overwrite_guard', 'off');
    expect($this->guard->mode())->toBe(NginxConfigGuard::MODE_OFF);
});
