<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Servers;

use App\Support\Servers\ProvisionStepDurations;
use App\Support\Servers\ProvisionStepSnapshots;
use PHPUnit\Framework\TestCase;

class ProvisionStepDurationsTest extends TestCase
{
    public function test_returns_empty_when_no_end_markers_present(): void
    {
        $output = "Some unrelated output\n[dply-step] Installing MySQL\nrandom log\n";

        $this->assertSame([], ProvisionStepDurations::parse($output));
    }

    public function test_parses_label_and_seconds_from_tab_delimited_marker(): void
    {
        $output = "[dply-step] Installing MySQL\n[dply-step-end] Installing MySQL\t125\n";

        $rows = ProvisionStepDurations::parse($output);

        $this->assertCount(1, $rows);
        $this->assertSame('Installing MySQL', $rows[0]['label']);
        $this->assertSame(125, $rows[0]['duration_seconds']);
        $this->assertFalse($rows[0]['resumed']);
    }

    public function test_resumed_marker_is_flagged(): void
    {
        $output = "[dply-step-end] Installing MySQL\t0\tresumed\n";

        $rows = ProvisionStepDurations::parse($output);

        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]['resumed']);
        $this->assertSame(0, $rows[0]['duration_seconds']);
    }

    public function test_resumed_label_suffix_normalizes_to_canonical_label(): void
    {
        // The script may also emit `[dply-step-end] Installing MySQL (resumed: already done)\t0\tresumed`
        // depending on which branch the bash hits. Whichever shape arrives,
        // the label_hash must match the canonical "Installing MySQL" hash so
        // the recorder lines them up against the non-resumed history.
        $resumedLabel = 'Installing MySQL (resumed: already done)';
        $output = "[dply-step-end] {$resumedLabel}\t0\tresumed\n";

        $rows = ProvisionStepDurations::parse($output);

        $this->assertCount(1, $rows);
        $this->assertSame('Installing MySQL', $rows[0]['label']);
        $this->assertSame(
            ProvisionStepSnapshots::keyForLabel('Installing MySQL'),
            $rows[0]['label_hash'],
        );
    }

    public function test_label_hash_matches_provision_step_snapshots_for_normal_run(): void
    {
        $output = "[dply-step-end] Installing PHP 8.4\t90\n";

        $rows = ProvisionStepDurations::parse($output);

        $this->assertCount(1, $rows);
        $this->assertSame(
            ProvisionStepSnapshots::keyForLabel('Installing PHP 8.4'),
            $rows[0]['label_hash'],
            'Duration parser must use the same hash function as the snapshot key generator so ETA lookups by step key resolve.',
        );
    }

    public function test_malformed_marker_lines_are_skipped(): void
    {
        $output = implode("\n", [
            '[dply-step-end] Installing MySQL',                  // missing tab
            '[dply-step-end] Installing PHP 8.4 not-a-number',   // missing tab + non-numeric
            '[dply-step-end] ',                                  // empty payload
            '[dply-step-end] Real Step	42',                       // valid (real tab char)
            '',
        ]);

        $rows = ProvisionStepDurations::parse($output);

        $this->assertCount(1, $rows);
        $this->assertSame('Real Step', $rows[0]['label']);
        $this->assertSame(42, $rows[0]['duration_seconds']);
    }

    public function test_multiple_steps_preserve_order(): void
    {
        $output = "[dply-step-end] First\t10\n[dply-step-end] Second\t20\n[dply-step-end] Third\t30\n";

        $rows = ProvisionStepDurations::parse($output);

        $this->assertSame(['First', 'Second', 'Third'], array_column($rows, 'label'));
        $this->assertSame([10, 20, 30], array_column($rows, 'duration_seconds'));
    }
}
