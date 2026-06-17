<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Logs\ServerLogAggregatorPolicyMapTest;

use App\Services\Logs\ServerLogAggregatorPolicyMap;
use App\Services\Logs\ServerLogEntitlement;

const GB = 1073741824; // 1024^3

/** @param array<string, mixed> $over */
function ent(array $over = []): ServerLogEntitlement
{
    return ServerLogEntitlement::fromConfig('plan', array_merge([
        'retention_days' => 7,
        'monthly_included_gb' => 10,
        'hard_cap_gb' => 0,
    ], $over));
}

test('a default-retention, uncapped org emits no row (covered by the aggregator default)', function () {
    $row = ServerLogAggregatorPolicyMap::buildRow('org_a', ent(['retention_days' => 7]), 500 * GB, 7);

    expect($row)->toBeNull();
});

test('a non-default retention org emits a row', function () {
    $row = ServerLogAggregatorPolicyMap::buildRow('org_a', ent(['retention_days' => 30]), 0, 7);

    expect($row)->toBe(['org_id' => 'org_a', 'retention_days' => 30, 'allowed' => true]);
});

test('an org over its hard cap emits allowed=false even at default retention', function () {
    $row = ServerLogAggregatorPolicyMap::buildRow('org_a', ent(['hard_cap_gb' => 20]), 25 * GB, 7);

    expect($row)->toBe(['org_id' => 'org_a', 'retention_days' => 7, 'allowed' => false]);
});

test('an org within its hard cap stays allowed (and omitted if default retention)', function () {
    $row = ServerLogAggregatorPolicyMap::buildRow('org_a', ent(['hard_cap_gb' => 20]), 5 * GB, 7);

    expect($row)->toBeNull();
});

test('hard cap of 0 never caps (fail open)', function () {
    $row = ServerLogAggregatorPolicyMap::buildRow('org_a', ent(['hard_cap_gb' => 0]), 9999 * GB, 7);

    expect($row)->toBeNull();
});

test('renders a CSV with a header and boolean as true/false', function () {
    $csv = ServerLogAggregatorPolicyMap::renderCsv([
        ['org_id' => 'org_a', 'retention_days' => 30, 'allowed' => true],
        ['org_id' => 'org_b', 'retention_days' => 7, 'allowed' => false],
    ]);

    expect($csv)->toBe(
        "org_id,retention_days,allowed\n"
        ."org_a,30,true\n"
        ."org_b,7,false\n"
    );
});

test('empty policy still renders just the header (valid enrichment file)', function () {
    expect(ServerLogAggregatorPolicyMap::renderCsv([]))->toBe("org_id,retention_days,allowed\n");
});
