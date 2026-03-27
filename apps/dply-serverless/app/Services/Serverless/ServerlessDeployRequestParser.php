<?php

namespace App\Services\Serverless;

use App\Models\ServerlessProject;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class ServerlessDeployRequestParser
{
    /**
     * @return array{function_name: string, runtime: string, artifact_path: string, serverless_project_id: int|null, idempotency_key: string|null}
     */
    public function parse(Request $request): array
    {
        $data = $this->jsonPayload($request);

        $functionName = isset($data['function_name']) ? (string) $data['function_name'] : (string) config('serverless.default_function_name');
        $runtime = isset($data['runtime']) ? (string) $data['runtime'] : (string) config('serverless.default_runtime');
        $artifactPath = isset($data['artifact_path']) ? (string) $data['artifact_path'] : (string) config('serverless.default_artifact_path');

        $this->assertNonEmpty('function_name', $functionName);
        $this->assertNonEmpty('runtime', $runtime);
        $this->assertNonEmpty('artifact_path', $artifactPath);
        $this->assertValidFunctionName($functionName);

        $projectId = null;
        $projectSlug = isset($data['project_slug']) ? trim((string) $data['project_slug']) : '';
        if ($projectSlug !== '') {
            $project = ServerlessProject::query()->where('slug', $projectSlug)->first();
            if ($project === null) {
                throw new InvalidArgumentException('Unknown project_slug.');
            }
            $projectId = $project->id;
        }

        return [
            'function_name' => $functionName,
            'runtime' => $runtime,
            'artifact_path' => $artifactPath,
            'serverless_project_id' => $projectId,
            'idempotency_key' => $this->resolveIdempotencyKey($request, $data),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveIdempotencyKey(Request $request, array $data): ?string
    {
        $header = $request->header('Idempotency-Key');
        $raw = '';
        if (is_string($header) && trim($header) !== '') {
            $raw = trim($header);
        } elseif (isset($data['idempotency_key'])) {
            $raw = trim((string) $data['idempotency_key']);
        }

        if ($raw === '') {
            return null;
        }

        if (strlen($raw) > 255) {
            throw new InvalidArgumentException('idempotency_key exceeds maximum length (255).');
        }

        return $raw;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonPayload(Request $request): array
    {
        if ($request->isJson()) {
            $decoded = $request->json()->all();

            return is_array($decoded) ? $decoded : [];
        }

        $raw = $request->getContent();
        if ($raw === '') {
            return [];
        }

        $data = json_decode($raw, true);
        if (! is_array($data)) {
            throw new InvalidArgumentException('Invalid JSON body.');
        }

        return $data;
    }

    private function assertNonEmpty(string $field, string $value): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException("{$field} cannot be empty.");
        }
    }

    private function assertValidFunctionName(string $name): void
    {
        if (strlen($name) > 128) {
            throw new InvalidArgumentException('function_name is too long.');
        }
        if (! preg_match('/^[a-zA-Z0-9._-]+$/', $name)) {
            throw new InvalidArgumentException('function_name may only contain letters, digits, dot, underscore, and hyphen.');
        }
    }
}
