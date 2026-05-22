<?php

declare(strict_types=1);

namespace App\Services\Serverless\Aws;

use InvalidArgumentException;

/**
 * Builds an Amazon States Language (ASL) definition for a dply sequence.
 *
 * An OpenWhisk sequence chains actions, threading each one's output into the
 * next. The Step Functions equivalent is a state machine of `Task` states,
 * one per component, linked by `Next`. Each Task uses the optimised
 * `lambda:invoke` integration keyed on the function *name* (so no ARN lookup
 * is needed); `OutputPath: $.Payload` unwraps the invoke result so the next
 * state receives the function's actual output, mirroring sequence semantics.
 *
 * Pure and side-effect free — the definition is unit-tested directly.
 */
final class StepFunctionsDefinition
{
    /**
     * @param  list<string>  $functionNames  ordered component function names
     *
     * @throws InvalidArgumentException
     */
    public static function forSequence(array $functionNames): string
    {
        $names = array_values(array_filter(
            array_map(static fn (string $n): string => trim($n), $functionNames),
            static fn (string $n): bool => $n !== '',
        ));

        if (count($names) < 2) {
            throw new InvalidArgumentException('A sequence must chain at least two actions.');
        }
        if (count($names) !== count(array_unique($names))) {
            throw new InvalidArgumentException('A Step Functions sequence cannot chain two actions with the same name.');
        }

        $states = [];
        $last = count($names) - 1;
        foreach ($names as $index => $name) {
            $state = [
                'Type' => 'Task',
                'Resource' => 'arn:aws:states:::lambda:invoke',
                'Parameters' => ['FunctionName' => $name, 'Payload.$' => '$'],
                'OutputPath' => '$.Payload',
            ];

            if ($index === $last) {
                $state['End'] = true;
            } else {
                $state['Next'] = $names[$index + 1];
            }

            $states[$name] = $state;
        }

        return (string) json_encode([
            'Comment' => 'dply serverless sequence',
            'StartAt' => $names[0],
            'States' => $states,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
