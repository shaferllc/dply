<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\Sites\DotEnvFileParser;
use Illuminate\Console\Command;

/**
 * Export a site's full configuration (runtime + processes + domains
 * + env vars) as a single JSON snapshot. Useful for backup,
 * disaster recovery, and migration to a different server.
 *
 *   dply:site:export-config <site>
 *   dply:site:export-config <site> --with-secrets   # include env values
 *   dply:site:export-config <site> --to=site.json
 *
 * Companion to:
 *   - dply:site:export-manifest — code-shape-only dply.yaml
 *   - dply:site:env-export      — env vars only as .env
 *   - this command              — full config snapshot as JSON
 *
 * SECURITY: --with-secrets writes cleartext env values to the
 * output. Without it, env vars are listed by KEY only with values
 * masked as '***'. Operators are responsible for treating the
 * --with-secrets output as sensitive.
 *
 * The schema includes a "format_version" so future imports can
 * detect older formats and migrate them.
 */
class ExportSiteConfigCommand extends Command
{
    public const FORMAT_VERSION = 1;

    protected $signature = 'dply:site:export-config
        {site : Site ID, slug, or name}
        {--with-secrets : Include cleartext env var values}
        {--to= : Write to file instead of stdout}
        {--force : Overwrite the destination file if it exists}';

    protected $description = 'Export a site\'s full configuration (runtime, processes, domains, env) as JSON.';

    public function handle(): int
    {
        $needle = (string) $this->argument('site');
        $site = $this->resolveSite($needle);
        if ($site === null) {
            $this->error("Site not found: {$needle}");

            return self::FAILURE;
        }

        $withSecrets = (bool) $this->option('with-secrets');
        $payload = $this->buildPayload($site, $withSecrets);
        $rendered = json_encode($payload, JSON_PRETTY_PRINT) ?: '{}';

        $to = (string) ($this->option('to') ?? '');
        if ($to === '') {
            $this->getOutput()->writeln($rendered);

            return self::SUCCESS;
        }

        if (file_exists($to) && ! (bool) $this->option('force')) {
            $this->error("Refusing to overwrite existing file: {$to} (use --force)");

            return self::FAILURE;
        }

        $bytes = file_put_contents($to, $rendered);
        if ($bytes === false) {
            $this->error("Failed to write to: {$to}");

            return self::FAILURE;
        }

        $this->info(sprintf('Exported config for %s to %s.', $site->name, $to));
        if ($withSecrets) {
            $this->line('<fg=yellow>Note: this file contains cleartext env values. Treat it as sensitive.</>');
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(Site $site, bool $withSecrets): array
    {
        $domains = $site->domains()
            ->orderByDesc('is_primary')
            ->orderBy('hostname')
            ->get(['hostname', 'is_primary', 'www_redirect'])
            ->map(fn ($d) => [
                'hostname' => $d->hostname,
                'is_primary' => (bool) $d->is_primary,
                'www_redirect' => (bool) $d->www_redirect,
            ])
            ->all();

        $processes = $site->processes()
            ->orderBy('type')
            ->orderBy('name')
            ->get(['type', 'name', 'command', 'scale', 'is_active', 'working_directory', 'user'])
            ->map(fn ($p) => [
                'type' => $p->type,
                'name' => $p->name,
                'command' => $p->command,
                'scale' => (int) $p->scale,
                'is_active' => (bool) $p->is_active,
                'working_directory' => $p->working_directory,
                'user' => $p->user,
            ])
            ->all();

        $parser = app(DotEnvFileParser::class);
        $variables = $parser->parse((string) ($site->env_file_content ?? ''))['variables'];
        ksort($variables);
        $envVars = [];
        foreach ($variables as $key => $value) {
            $envVars[] = [
                'environment' => (string) ($site->deployment_environment ?: 'production'),
                'key' => $key,
                'value' => $withSecrets ? (string) $value : '***',
            ];
        }

        return [
            'format_version' => self::FORMAT_VERSION,
            'exported_at' => now()->toIso8601String(),
            'with_secrets' => $withSecrets,
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
                'slug' => $site->slug,
                'runtime' => $site->runtime,
                'runtime_version' => $site->runtime_version,
                'build_command' => $site->build_command,
                'start_command' => $site->start_command,
                'internal_port' => $site->internal_port,
                'database_engine' => $site->database_engine,
                'document_root' => $site->document_root,
                'repository_path' => $site->repository_path,
                'git_repository_url' => $site->git_repository_url,
                'git_branch' => $site->git_branch,
            ],
            'domains' => $domains,
            'processes' => $processes,
            'environment_variables' => $envVars,
        ];
    }

    private function resolveSite(string $needle): ?Site
    {
        $needle = trim($needle);
        if ($needle === '') {
            return null;
        }

        return Site::query()->where('id', $needle)
            ->orWhere('slug', $needle)
            ->orWhere('name', $needle)
            ->first();
    }
}
