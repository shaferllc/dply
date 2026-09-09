<?php

declare(strict_types=1);

namespace App\Actions;

use App\Actions\Concerns\AsAction;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;

/**
 * Base class for application actions.
 *
 * Actions encapsulate single-purpose business logic and can be executed
 * in multiple contexts (controller, job, listener, command, object).
 *
 * Every concrete action defines handle() with its own domain signature
 * (e.g. `handle(Team $team, array $formData): Tag`), which is why this cannot
 * be an `abstract function` — those signatures are not contravariant with a
 * variadic parent and PHP would reject them. The @method tag states the
 * contract the As* traits rely on; a subclass's real handle() still overrides
 * it and is type-checked normally.
 *
 * @method mixed handle(mixed ...$arguments)
 *
 * @phpstan-consistent-constructor
 *   The As* traits construct actions late-bound — AsEvent does
 *   `new static(...$arguments)`, AsResource `new static($item)`. The
 *   constructor comes from AsDependent and is variadic, so every subclass
 *   shares it; declaring that makes the late-bound construction safe
 *   instead of unchecked, and PHPStan now enforces the consistency.
 */
abstract class Actions
{
    use AsAction;

    /**
     * Handle validation failure by throwing an HTTP response exception.
     *
     * @param  Validator  $validator  The failed validator instance
     *
     * @throws HttpResponseException
     */
    public function getValidationFailure(Validator $validator): never
    {
        throw new HttpResponseException(
            new JsonResponse([
                'message' => 'The given data was invalid.',
                'errors' => $validator->errors()->toArray(),
            ], 422)
        );
    }

    /**
     * Handle unauthorized access by throwing an HTTP response exception.
     *
     * @param  string|null  $message  Custom unauthorized message
     *
     * @throws HttpResponseException
     */
    public function unauthorizedResponse(?string $message = null): never
    {
        throw new HttpResponseException(
            new JsonResponse([
                'unauthorized' => $message ?? 'You are not authorized to perform this action.',
            ], 422)
        );
    }
}
