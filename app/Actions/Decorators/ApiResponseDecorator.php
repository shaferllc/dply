<?php

declare(strict_types=1);

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;
use Illuminate\Http\JsonResponse;

/**
 * Decorator that formats action responses as standardized API responses.
 *
 * This decorator automatically wraps action results in a standardized JSON
 * response format with success/error structure and appropriate HTTP status codes.
 */
class ApiResponseDecorator
{
    use DecorateActions;

    public function __construct($action)
    {
        $this->setAction($action);
    }

    public function handle(...$arguments)
    {
        try {
            $result = $this->callMethod('handle', $arguments);

            return $this->formatSuccessResponse($result);
        } catch (\Throwable $e) {
            return $this->formatErrorResponse($e);
        }
    }

    protected function formatSuccessResponse($data): JsonResponse
    {
        $formatted = $this->formatResponse($data);

        return response()->json($formatted, $this->getSuccessStatusCode());
    }

    protected function formatErrorResponse(\Throwable $exception): JsonResponse
    {
        $formatted = $this->formatError($exception);

        return response()->json($formatted, $this->getErrorStatusCode($exception));
    }

    protected function formatResponse($data): array
    {
        if ($this->hasMethod('formatResponse')) {
            return $this->callMethod('formatResponse', [$data]);
        }

        return [
            'success' => true,
            'data' => $data,
        ];
    }

    protected function formatError(\Throwable $exception): array
    {
        if ($this->hasMethod('formatError')) {
            return $this->callMethod('formatError', [$exception]);
        }

        $response = [
            'success' => false,
            'error' => [
                'message' => $exception->getMessage(),
                'code' => $exception->getCode() ?: 'ERROR',
            ],
        ];

        if (config('app.debug')) {
            $response['error']['trace'] = $exception->getTraceAsString();
            $response['error']['file'] = $exception->getFile();
            $response['error']['line'] = $exception->getLine();
        }

        return $response;
    }

    protected function getSuccessStatusCode(): int
    {
        if ($this->hasMethod('getSuccessStatusCode')) {
            return $this->callMethod('getSuccessStatusCode');
        }

        return 200;
    }

    protected function getErrorStatusCode(\Throwable $exception): int
    {
        if ($this->hasMethod('getErrorStatusCode')) {
            return $this->callMethod('getErrorStatusCode', [$exception]);
        }

        if (method_exists($exception, 'getStatusCode')) {
            return $exception->getStatusCode();
        }

        return 500;
    }
}
