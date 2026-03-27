<?php

namespace App\Services\Wordpress;

use App\Models\WordpressProject;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class WordpressDeployRequestParser
{
    /**
     * @return array{wordpress_project_id: int, application_name: string, php_version: string, git_ref: string, idempotency_key: string|null}
     */
    public function parse(Request $request): array
    {
        $data = $this->jsonPayload($request);

        $projectSlug = isset($data['project_slug']) ? trim((string) $data['project_slug']) : '';
        if ($projectSlug === '') {
            throw new InvalidArgumentException('project_slug is required.');
        }

        $project = WordpressProject::query()->where('slug', $projectSlug)->first();
        if ($project === null) {
            throw new InvalidArgumentException('Unknown project_slug.');
        }

        $defaultPhp = (string) config('wordpress.default_php_version', '8.3');
        $defaultGitRef = (string) config('wordpress.default_git_ref', 'main');

        $phpVersion = isset($data['php_version']) ? trim((string) $data['php_version']) : $defaultPhp;
        if ($phpVersion === '') {
            $phpVersion = $defaultPhp;
        }
        $this->assertValidPhpVersion($phpVersion);

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
            'wordpress_project_id' => $project->id,
            'application_name' => $applicationName,
            'php_version' => $phpVersion,
            'git_ref' => $gitRef,
            'idempotency_key' => $this->resolveIdempotencyKey($request, $data),
        ];
    }

    private function assertValidPhpVersion(string $value): void
    {
        if (strlen($value) > 16) {
            throw new InvalidArgumentException('php_version is too long.');
        }
        if (! preg_match('/^\d+(\.\d+){0,2}$/', $value)) {
            throw new InvalidArgumentException('php_version must look like a PHP version (e.g. 8.3 or 8.3.0).');
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
