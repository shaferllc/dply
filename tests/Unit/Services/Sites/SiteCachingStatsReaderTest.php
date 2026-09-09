<?php

declare(strict_types=1);

use App\Services\Sites\SiteCachingStatsReader;

test('parseVarnishstatJson reads modern counters object', function () {
    $json = json_encode([
        'version' => 1,
        'counters' => [
            'MAIN.cache_hit' => ['value' => 80],
            'MAIN.cache_miss' => ['value' => 20],
            'MAIN.n_object' => ['value' => 12],
            'MAIN.n_lru_nuked' => ['value' => 1],
        ],
    ], JSON_THROW_ON_ERROR);

    $parsed = SiteCachingStatsReader::parseVarnishstatJson($json);

    expect($parsed)->not->toBeNull()
        ->and($parsed['hits'])->toBe(80)
        ->and($parsed['misses'])->toBe(20)
        ->and($parsed['hit_rate'])->toBe(80.0)
        ->and($parsed['objects'])->toBe(12)
        ->and($parsed['nuked'])->toBe(1)
        ->and($parsed['scope'])->toBe('server');
});

test('parseVarnishstatJson reads flat varnishstat -j payload', function () {
    $json = json_encode([
        'MAIN.cache_hit' => ['value' => 3],
        'MAIN.cache_miss' => ['value' => 1],
    ], JSON_THROW_ON_ERROR);

    $parsed = SiteCachingStatsReader::parseVarnishstatJson($json);

    expect($parsed['hit_rate'])->toBe(75.0);
});

test('parseVarnishstatJson returns null for garbage', function () {
    expect(SiteCachingStatsReader::parseVarnishstatJson('not-json'))->toBeNull()
        ->and(SiteCachingStatsReader::parseVarnishstatJson(''))->toBeNull();
});

test('extractBetween pulls varnishstat payload', function () {
    $buffer = "fcgi_bytes=10\nVARNISH_JSON_BEGIN\n{\"MAIN.cache_hit\":{\"value\":1}}\nVARNISH_JSON_END\n";

    expect(SiteCachingStatsReader::extractBetween($buffer, 'VARNISH_JSON_BEGIN', 'VARNISH_JSON_END'))
        ->toBe('{"MAIN.cache_hit":{"value":1}}');
});
