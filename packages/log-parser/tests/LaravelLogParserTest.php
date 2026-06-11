<?php

declare(strict_types=1);

use Dply\LogParser\LaravelLogParser;

beforeEach(fn () => $this->parser = new LaravelLogParser);

it('parses a record with context and extra', function () {
    $records = $this->parser->parse('[2026-06-10 14:23:01] production.ERROR: Boom {"exception":"x"} []');

    expect($records)->toHaveCount(1);
    $r = $records[0];
    expect($r['parsed'])->toBeTrue()
        ->and($r['channel'])->toBe('production')
        ->and($r['level'])->toBe('ERROR')
        ->and($r['message'])->toBe('Boom')
        ->and($r['context'])->toBe(['exception' => 'x'])
        ->and($r['extra'])->toBe([])
        ->and($r['datetime']?->format('Y-m-d H:i:s'))->toBe('2026-06-10 14:23:01');
});

it('groups continuation lines as the trace of the preceding record', function () {
    $log = "[2026-06-10 14:23:01] production.ERROR: Boom {\"exception\":\"x\"} []\n#0 /app/x.php(20): handler()\n#1 {main}\n[2026-06-10 14:23:05] production.INFO: ok [] []";

    $records = $this->parser->parse($log);

    expect($records)->toHaveCount(2)
        ->and($records[0]['trace'])->toBe(['#0 /app/x.php(20): handler()', '#1 {main}'])
        ->and($records[1]['level'])->toBe('INFO')
        ->and($records[1]['trace'])->toBe([]);
});

it('does not mistake a message ending in a brace for context', function () {
    $records = $this->parser->parse('[2026-06-10 14:23:01] local.DEBUG: array looks like {this}');

    expect($records[0]['message'])->toBe('array looks like {this}')
        ->and($records[0]['context'])->toBeNull();
});

it('parses a message with no json blocks', function () {
    $records = $this->parser->parse('[2026-06-10 14:24:00] local.DEBUG: plain message');

    expect($records[0]['message'])->toBe('plain message')
        ->and($records[0]['context'])->toBeNull()
        ->and($records[0]['extra'])->toBeNull();
});

it('surfaces a leading continuation fragment as unparsed', function () {
    $records = $this->parser->parse("#0 orphaned trace line\n[2026-06-10 14:24:00] local.INFO: hi [] []");

    expect($records[0]['parsed'])->toBeFalse()
        ->and($records[0]['raw'])->toBe('#0 orphaned trace line')
        ->and($records[1]['parsed'])->toBeTrue();
});

it('returns an empty list for empty input', function () {
    expect($this->parser->parse(''))->toBe([]);
});
