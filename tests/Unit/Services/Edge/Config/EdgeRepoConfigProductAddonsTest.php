<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Edge\Config;

use App\Modules\Edge\Services\Config\EdgeRepoConfigLinter;
use App\Modules\Edge\Services\Config\EdgeRepoConfigLoader;

uses()->group('edge');

test('loader normalizes tags snippets and forms from dply.yaml', function () {
    $yaml = <<<'YAML'
tags:
  enabled: true
  consent_required: true
  tools:
    - name: GA4
      src: https://www.googletagmanager.com/gtag/js?id=G-TEST
      async: true
snippets:
  enabled: true
  items:
    - name: Meta
      phase: head
      path: /*
      html: '<meta name="description" content="hi">'
forms:
  enabled: true
  endpoints:
    - path: contact
      to_email: ops@example.com
      honeypot: company
      require_turnstile: false
YAML;

    $config = (new EdgeRepoConfigLoader)->parse('dply.yaml', $yaml);

    expect($config->tags['enabled'])->toBeTrue()
        ->and($config->tags['consent_required'])->toBeTrue()
        ->and($config->tags['tools'][0]['src'])->toContain('gtag/js')
        ->and($config->snippets['items'][0]['phase'])->toBe('head')
        ->and($config->forms['endpoints'][0]['path'])->toBe('/contact')
        ->and($config->forms['endpoints'][0]['require_turnstile'])->toBeFalse();

    $lint = (new EdgeRepoConfigLinter(new EdgeRepoConfigLoader))->lint($config);
    expect($lint['ok'])->toBeTrue()
        ->and($lint['summary']['tags'])->toBe(1)
        ->and($lint['summary']['snippets'])->toBe(1)
        ->and($lint['summary']['forms'])->toBe(1);
});

test('loader warns on invalid tag src and form email', function () {
    $yaml = <<<'YAML'
tags:
  tools:
    - name: Bad
      src: http://insecure.example/x.js
forms:
  endpoints:
    - path: /x
      to_email: not-an-email
YAML;

    $config = (new EdgeRepoConfigLoader)->parse('dply.yaml', $yaml);

    expect($config->tags['tools'] ?? [])->toBe([])
        ->and($config->forms['endpoints'] ?? [])->toBe([])
        ->and($config->warnings)->not->toBeEmpty();
});
