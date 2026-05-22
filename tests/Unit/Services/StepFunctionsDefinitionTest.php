<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Serverless\Aws\StepFunctionsDefinition;
use InvalidArgumentException;
use Tests\TestCase;

class StepFunctionsDefinitionTest extends TestCase
{
    public function test_it_chains_functions_into_a_state_machine(): void
    {
        $definition = json_decode(
            StepFunctionsDefinition::forSequence(['fetch', 'transform', 'store']),
            true,
        );

        $this->assertSame('fetch', $definition['StartAt']);
        $this->assertSame(['fetch', 'transform', 'store'], array_keys($definition['States']));

        // Each state invokes its Lambda and threads output into the next.
        $this->assertSame('transform', $definition['States']['fetch']['Next']);
        $this->assertSame('store', $definition['States']['transform']['Next']);
        $this->assertTrue($definition['States']['store']['End']);

        $this->assertSame('fetch', $definition['States']['fetch']['Parameters']['FunctionName']);
        $this->assertSame('arn:aws:states:::lambda:invoke', $definition['States']['fetch']['Resource']);
        $this->assertSame('$.Payload', $definition['States']['fetch']['OutputPath']);
    }

    public function test_it_rejects_a_sequence_shorter_than_two_actions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        StepFunctionsDefinition::forSequence(['solo']);
    }

    public function test_it_rejects_duplicate_function_names(): void
    {
        $this->expectException(InvalidArgumentException::class);
        StepFunctionsDefinition::forSequence(['process', 'process']);
    }
}
