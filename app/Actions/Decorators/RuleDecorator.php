<?php

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;
use Illuminate\Contracts\Validation\Rule;

/**
 * Decorates actions when used as validation rules.
 *
 * @example
 * // When an action with AsRule is used in validation:
 * $validator->validate(['email' => 'test@example.com'], [
 *     'email' => ['required', new UniqueEmailRule],
 * ]);
 *
 * // This decorator implements the Rule interface and calls
 * // passes() or handle() on the action, and message() or getMessage()
 * // for error messages.
 */
class RuleDecorator implements Rule
{
    use DecorateActions;

    public function __construct($action)
    {
        $this->setAction($action);
    }

    public function passes($attribute, $value): bool
    {
        // Try passes() method first (standard Rule interface)
        if ($this->hasMethod('passes')) {
            return (bool) $this->callMethod('passes', [$attribute, $value]);
        }

        // Try handle() method with attribute and value
        if ($this->hasMethod('handle')) {
            return (bool) $this->callMethod('handle', [$attribute, $value]);
        }

        // Try validate() method
        if ($this->hasMethod('validate')) {
            return (bool) $this->callMethod('validate', [$attribute, $value]);
        }

        return false;
    }

    public function message(): string
    {
        // Try message() method first (standard Rule interface)
        if ($this->hasMethod('message')) {
            return (string) $this->callMethod('message');
        }

        // Try getMessage() method
        if ($this->hasMethod('getMessage')) {
            return (string) $this->callMethod('getMessage');
        }

        // Try errorMessage() method
        if ($this->hasMethod('errorMessage')) {
            return (string) $this->callMethod('errorMessage');
        }

        // Default message
        return 'The :attribute is invalid.';
    }
}
