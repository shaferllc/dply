<?php

namespace App\Services\Edge;

use App\Models\EdgeProject;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class EdgeDeployRequestParser
{
    /** @var list<string> */
    private const ALLOWED_FRAMEWORKS = ['next', 'nuxt', 'astro', 'static', 'remix'];

    /**
     * @return array{edge_project_id: int, application_name: string, framework: string, git_ref: string, idempotency_key: string|null}
     */
    public function parse(Request $request): array
    {
        $data = $this->jsonPayload($request);

        $projectSlug = isset($data['project_slug']) ? trim((string) $data['project_slug']) : '';
        if ($projectSlug === '') {
            throw new InvalidArgumentException('project_slug is required.');
        }

        $project = EdgeProject::query()->where('slug', $projectSlug)->first();
        if ($project === null) {
            throw new InvalidArgumentException('Unknown project_slug.');
        }

        $defaultFw = (string) config('edge.default_framework', 'next');
        $defaultGitRef = (string) config('edge.default_git_ref', 'main');

        $framework = isset($data['framework']) ? trim((string) $data['framework']) : $defaultFw;
        if ($framework === '') {
            $framework = $defaultFw;
        }
        $this->assertValidFramework($framework);

        $gitRef = isset($data['git_ref']) ? trim((string) $data['git_ref']) : $defaultGitRef;
        if ($gitRef === '') {
            $gitRef = $defaultGitRef;
        }
        if (strlen($gitRef) > 255) {
            throw new InvalidArgumentException('git_ref is too long.');
        }

        $applicationName = isset($data['application_name']) ? trim((string) $data['application_name']) : '';
        if ($applicationName === '') {
            $applicationName = $project->name;
        }
        if (strlen($applicationName) > 255) {
            throw new InvalidArgumentException('application_name is too long.');
        }

        return [
            'edge_project_id' => $project->id,
            'application_name' => $applicationName,
            'framework' => $framework,
            'git_ref' => $gitRef,
            'idempotency_key' => $this->resolveIdempotencyKey($request, $data),
        ];
    }

    private function assertValidFramework(string $value): void
    {
        if (strlen($value) > 32) {
            throw new InvalidArgumentException('framework is too long.');
        }
        if (! in_array($value, self::ALLOWED_FRAMEWORKS, true)) {
            throw new InvalidArgumentException('framework must be one of: '.implode(', ', self::ALLOWED_FRAMEWORKS).'.');
        }
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
}
