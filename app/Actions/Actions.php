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
