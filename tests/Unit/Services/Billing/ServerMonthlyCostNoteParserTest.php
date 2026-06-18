<?php

declare(strict_types=1);

use App\Modules\Billing\Services\ServerMonthlyCostNoteParser;

test('parses provider-pull formatted cost notes', function () {
    $parser = new ServerMonthlyCostNoteParser;

    $parsed = $parser->parse('~$12.00/mo · Hetzner cx22 (catalog price, fetched 2026-05-27) EUR');

    expect($parsed)->toBe(['amount' => 12.0, 'currency' => 'USD']);
});

test('parses manual dollar monthly notes', function () {
    $parser = new ServerMonthlyCostNoteParser;

    expect($parser->parse('~$48/mo on annual commit'))->toBe(['amount' => 48.0, 'currency' => 'USD']);
    expect($parser->parse('€15/mo Frankfurt'))->toBe(['amount' => 15.0, 'currency' => 'EUR']);
});

test('returns null for unparseable notes', function () {
    $parser = new ServerMonthlyCostNoteParser;

    expect($parser->parse(null))->toBeNull();
    expect($parser->parse('negotiated annually'))->toBeNull();
});

test('converts eur to usd cents', function () {
    $parser = new ServerMonthlyCostNoteParser;

    expect($parser->toUsdCents(10.0, 'EUR'))->toBe(1080);
    expect($parser->toUsdCents(5.0, 'USD'))->toBe(500);
});
