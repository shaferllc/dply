<?php

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsRule;
use App\Actions\Decorators\RuleDecorator;
use Illuminate\Contracts\Validation\Rule as RuleContract;
use Illuminate\Validation\Validator;

/**
 * Recognizes when actions are used as validation rules.
 *
 * @example
 * // Action class:
 * class UniqueEmailRule extends Actions implements Rule
 * {
 *     use AsRule;
 *
 *     public function handle($attribute, $value): bool
 *     {
 *         return ! User::where('email', $value)->exists();
 *     }
 *
 *     public function getMessage(): string
 *     {
 *         return 'This email is already taken.';
 *     }
 * }
 *
 * // Usage in FormRequest:
 * public function rules()
 * {
 *     return [
 *         'email' => ['required', 'email', new UniqueEmailRule],
 *     ];
 * }
 *
 * // The design pattern automatically recognizes when the action
 * // is used as a validation rule and decorates it appropriately.
 */
class RuleDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsRule::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        return $frame->instanceOf(RuleContract::class)
            || $frame->matches(Validator::class, 'validate')
            || $frame->matches(Validator::class, 'validateRule');
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        return app(RuleDecorator::class, ['action' => $instance]);
    }
}
