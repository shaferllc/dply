<?php

declare(strict_types=1);

use Dply\LogParser\WebserverErrorLogParser;

beforeEach(fn () => $this->parser = new WebserverErrorLogParser);

it('parses an nginx error line with a full structured tail', function () {
    $line = '2026/06/10 14:23:01 [error] 1234#5678: *90 FastCGI sent in stderr: "PHP message: Boom", '
        .'client: 1.2.3.4, server: example.com, request: "GET /x HTTP/1.1", '
        .'upstream: "fastcgi://unix:/run/php/app.sock", host: "example.com"';

    $e = $this->parser->parse($line)[0];

    expect($e['parsed'])->toBeTrue()
        ->and($e['type'])->toBe('nginx')
        ->and($e['level'])->toBe('error')
        ->and($e['pid'])->toBe(1234)
        ->and($e['connection'])->toBe(90)
        ->and($e['message'])->toBe('FastCGI sent in stderr: "PHP message: Boom"')
        ->and($e['client'])->toBe('1.2.3.4')
        ->and($e['server'])->toBe('example.com')
        ->and($e['request'])->toBe('GET /x HTTP/1.1')
        ->and($e['host'])->toBe('example.com')
        ->and($e['datetime']?->format('Y-m-d H:i:s'))->toBe('2026-06-10 14:23:01');
});

it('parses an nginx line without a structured tail', function () {
    $e = $this->parser->parse('2026/06/10 14:25:00 [warn] 1234#5678: conflicting server name')[0];

    expect($e['message'])->toBe('conflicting server name')
        ->and($e['level'])->toBe('warn')
        ->and($e['client'])->toBeNull();
});

it('parses an apache 2.4 error line and splits module from level', function () {
    $e = $this->parser->parse('[Wed Jun 10 14:23:01.123456 2026] [php:error] [pid 1234] [client 1.2.3.4:55] PHP Fatal error: boom')[0];

    expect($e['type'])->toBe('apache')
        ->and($e['module'])->toBe('php')
        ->and($e['level'])->toBe('error')
        ->and($e['pid'])->toBe(1234)
        ->and($e['client'])->toBe('1.2.3.4:55')
        ->and($e['message'])->toBe('PHP Fatal error: boom');
});

it('groups continuation lines onto the preceding entry', function () {
    $log = "2026/06/10 14:23:01 [error] 1#1: *1 PHP message: Stack trace:\n"
        ."PHP   1. {main}() /app/index.php:0\n"
        .'2026/06/10 14:30:00 [error] 1#1: *2 second error';

    $entries = $this->parser->parse($log);

    expect($entries)->toHaveCount(2)
        ->and($entries[0]['trace'])->toBe(['PHP   1. {main}() /app/index.php:0'])
        ->and($entries[1]['message'])->toBe('second error');
});

it('marks an unrecognized line as unparsed', function () {
    $e = $this->parser->parse('this is not an error log line')[0];

    expect($e['parsed'])->toBeFalse()
        ->and($e['raw'])->toBe('this is not an error log line');
});
