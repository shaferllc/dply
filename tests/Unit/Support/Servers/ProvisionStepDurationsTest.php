<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Servers\ProvisionStepDurationsTest;

use App\Support\Servers\ProvisionStepDurations;
use App\Support\Servers\ProvisionStepSnapshots;

test('returns empty when no end markers present', function () {
    $output = "Some unrelated output\n[dply-step] Installing MySQL\nrandom log\n";

    expect(ProvisionStepDurations::parse($output))->toBe([]);
});
test('parses label and seconds from tab delimited marker', function () {
    $output = "[dply-step] Installing MySQL\n[dply-step-end] Installing MySQL\t125\n";

    $rows = ProvisionStepDurations::parse($output);

    expect($rows)->toHaveCount(1);
    expect($rows[0]['label'])->toBe('Installing MySQL');
    expect($rows[0]['duration_seconds'])->toBe(125);
    expect($rows[0]['resumed'])->toBeFalse();
});
test('resumed marker is flagged', function () {
    $output = "[dply-step-end] Installing MySQL\t0\tresumed\n";

    $rows = ProvisionStepDurations::parse($output);

    expect($rows)->toHaveCount(1);
    expect($rows[0]['resumed'])->toBeTrue();
    expect($rows[0]['duration_seconds'])->toBe(0);
});
test('resumed label suffix normalizes to canonical label', function () {
    // The script may also emit `[dply-step-end] Installing MySQL (resumed: already done)\t0\tresumed`
    // depending on which branch the bash hits. Whichever shape arrives,
    // the label_hash must match the canonical "Installing MySQL" hash so
    // the recorder lines them up against the non-resumed history.
    $resumedLabel = 'Installing MySQL (resumed: already done)';
    $output = "[dply-step-end] {$resumedLabel}\t0\tresumed\n";

    $rows = ProvisionStepDurations::parse($output);

    expect($rows)->toHaveCount(1);
    expect($rows[0]['label'])->toBe('Installing MySQL');
    expect($rows[0]['label_hash'])->toBe(ProvisionStepSnapshots::keyForLabel('Installing MySQL'));
});
test('label hash matches provision step snapshots for normal run', function () {
    $output = "[dply-step-end] Installing PHP 8.4\t90\n";

    $rows = ProvisionStepDurations::parse($output);

    expect($rows)->toHaveCount(1);
    expect($rows[0]['label_hash'])->toBe(ProvisionStepSnapshots::keyForLabel('Installing PHP 8.4'), 'Duration parser must use the same hash function as the snapshot key generator so ETA lookups by step key resolve.');
});
test('malformed marker lines are skipped', function () {
    $output = implode("\n", [
        '[dply-step-end] Installing MySQL',                  // missing tab
        '[dply-step-end] Installing PHP 8.4 not-a-number',   // missing tab + non-numeric
        '[dply-step-end] ',                                  // empty payload
        '[dply-step-end] Real Step	42',                       // valid (real tab char)
        '',
    ]);

    $rows = ProvisionStepDurations::parse($output);

    expect($rows)->toHaveCount(1);
    expect($rows[0]['label'])->toBe('Real Step');
    expect($rows[0]['duration_seconds'])->toBe(42);
});
test('multiple steps preserve order', function () {
    $output = "[dply-step-end] First\t10\n[dply-step-end] Second\t20\n[dply-step-end] Third\t30\n";

    $rows = ProvisionStepDurations::parse($output);

    expect(array_column($rows, 'label'))->toBe(['First', 'Second', 'Third']);
    expect(array_column($rows, 'duration_seconds'))->toBe([10, 20, 30]);
});
