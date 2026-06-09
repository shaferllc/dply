<?php

declare(strict_types=1);

namespace App\Services\Edge\Importers;

/**
 * Common contract for the per-provider import services. Implementing
 * a new provider means: implement {@see listProjects} (read-only,
 * shows what could be imported) and {@see fetchProject} (pulls the
 * detail rows the wizard turns into a Create-form prefill).
 *
 * Importers never write to the dply database directly — the wizard
 * owns persistence. This keeps the providers as pure adapters that
 * are easy to test against fixtures.
 */
interface EdgeImporter
{
    /**
     * Validate the credential against the provider — returns a tuple
     * describing the authenticated principal so the wizard can show
     * "logged in as …" before listing projects.
     *
     * @return array{ok: bool, message: string, principal?: string}
     */
    public function probe(): array;

    /**
     * @return list<array{id: string, name: string, repo: ?string, framework: ?string, live_url: ?string, updated_at: ?string}>
     */
    public function listProjects(): array;

    public function fetchProject(string $projectId): ImportedEdgeProject;

    /**
     * Provider-stable slug used in routes + tokens config (`vercel`,
     * `netlify`, `cloudflare_pages`).
     */
    public function providerKey(): string;

    public function providerLabel(): string;
}
