<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Services;

use App\Models\FunctionAction;
use App\Models\Site;
use App\Modules\Serverless\Support\FunctionConfiguration;
use App\Modules\Serverless\Support\FunctionCorsPolicy;
use Symfony\Component\Yaml\Yaml;

/**
 * Writes a `project.yml` describing a Site as dply has it configured.
 *
 * The other direction of the manifest: a repo's manifest tells dply how to
 * deploy, and this tells the repo what dply is currently doing. That closes
 * the loop for anyone moving between `doctl serverless deploy` and dply, and
 * makes a function's configuration reviewable in the repository rather than
 * living only in dply's database.
 *
 * Bound parameter *values* are never written out. A parameter routinely holds
 * a credential, and a generated file is going to be committed — so each one
 * is emitted as a `${NAME}` reference the manifest resolves from the
 * environment at deploy time.
 *
 * @see https://docs.digitalocean.com/products/functions/reference/project-configuration/
 */
class ServerlessProjectManifestWriter
{
    /**
     * Render the manifest as YAML.
     */
    public function render(Site $site): string
    {
        return Yaml::dump($this->toArray($site), 6, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Site $site): array
    {
        $site->loadMissing('functionActions');

        $configuration = FunctionConfiguration::fromSiteConfig($site->serverlessConfig());
        $package = trim((string) ($site->serverlessConfig()['package'] ?? 'default')) ?: 'default';

        $actions = $site->functionActions
            ->where('kind', FunctionAction::KIND_CODE)
            ->values();

        // A Site that has never been deployed has no action rows yet; describe
        // the one the next deploy will create rather than emitting an empty
        // package the reader would have to interpret.
        $functions = $actions->isEmpty()
            ? [$this->functionFromSite($site, $configuration)]
            : $actions->map(fn (FunctionAction $action): array => $this->functionFromAction($site, $action, $configuration))->all();

        return [
            'packages' => [
                array_filter([
                    'name' => $package,
                    'functions' => array_values($functions),
                ], static fn (mixed $value): bool => $value !== []),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function functionFromAction(Site $site, FunctionAction $action, FunctionConfiguration $configuration): array
    {
        $subdir = trim((string) ($action->meta['source_subdir'] ?? ''));
        $siteLimits = $site->serverlessLimits();
        $limits = [
            'timeout' => (int) round(((int) ($action->timeout_ms ?: $siteLimits['timeout'])) / 1000),
            'memory' => (int) ($action->memory_mb ?: $siteLimits['memory']),
            'logs' => $siteLimits['logs'],
        ];

        if (($action->concurrency ?? 0) > 1) {
            $limits['concurrency'] = (int) $action->concurrency;
        }

        return array_filter([
            'name' => $action->name,
            'function' => $subdir !== '' ? $subdir : '.',
            'runtime' => (string) $action->runtime,
            'main' => (string) ($action->entrypoint ?: 'main'),
            'web' => $this->web($configuration),
            'limits' => $limits,
            'parameters' => $this->parameterReferences($configuration),
            'annotations' => $this->annotations($configuration),
        ], static fn (mixed $value): bool => $value !== '' && $value !== []);
    }

    /**
     * @return array<string, mixed>
     */
    private function functionFromSite(Site $site, FunctionConfiguration $configuration): array
    {
        $config = $site->serverlessConfig();
        $limits = $site->serverlessLimits();

        return array_filter([
            'name' => $site->serverlessActionName() ?: (string) $site->slug,
            'function' => '.',
            'runtime' => trim((string) ($config['runtime'] ?? '')),
            'main' => trim((string) ($config['entrypoint'] ?? '')) ?: 'main',
            'web' => $this->web($configuration),
            'limits' => [
                'timeout' => (int) round($limits['timeout'] / 1000),
                'memory' => $limits['memory'],
                'logs' => $limits['logs'],
            ],
            'parameters' => $this->parameterReferences($configuration),
            'annotations' => $this->annotations($configuration),
        ], static fn (mixed $value): bool => $value !== '' && $value !== []);
    }

    /** The manifest's tri-state `web:` value. */
    private function web(FunctionConfiguration $configuration): string|bool
    {
        return match ($configuration->webMode) {
            FunctionConfiguration::MODE_RAW => 'raw',
            FunctionConfiguration::MODE_OFF => false,
            default => true,
        };
    }

    /**
     * Parameter names bound to `${NAME}` references — never the values.
     *
     * @return array<string, string>
     */
    private function parameterReferences(FunctionConfiguration $configuration): array
    {
        $out = [];

        foreach (array_keys($configuration->parameters) as $key) {
            $key = (string) $key;
            // The reference syntax only accepts an identifier, so a parameter
            // name that is not one is written as an empty value for the
            // operator to fill in rather than as a reference that can't resolve.
            $out[$key] = preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) === 1 ? '${'.$key.'}' : '';
        }

        return $out;
    }

    /**
     * The annotations that are not already implied by `web:` — the CORS
     * policy is the one worth writing down, since it changes what callers can
     * do rather than just how the function is reached.
     *
     * @return array<string, mixed>
     */
    private function annotations(FunctionConfiguration $configuration): array
    {
        $annotations = [];

        if ($configuration->isWebEnabled() && $configuration->cors->enabled) {
            $annotations['web-custom-options'] = true;
            $annotations[FunctionCorsPolicy::PARAMETER_KEY] = $configuration->cors->toParameter();
        }

        if ($configuration->provideApiKey) {
            $annotations['provide-api-key'] = true;
        }

        if ($configuration->finalParameters && $configuration->parameters !== []) {
            $annotations['final'] = true;
        }

        return $annotations;
    }
}
