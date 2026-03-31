<?php

declare(strict_types=1);

namespace App\Actions\Console;

use App\Actions\ActionRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class GenerateActionDocsCommand extends Command
{
    protected $signature = 'actions:docs
                            {--format=markdown : Output format (markdown, openapi, json)}
                            {--output= : Output file path}
                            {--action= : Generate docs for specific action}';

    protected $description = 'Generate documentation for actions';

    /**
     * Generate documentation for actions.
     *
     * @example
     * // Generate markdown documentation
     * php artisan actions:docs
     * php artisan actions:docs --format=markdown --output=docs/actions.md
     * @example
     * // Generate OpenAPI specification
     * php artisan actions:docs --format=openapi --output=openapi.json
     * @example
     * // Generate JSON documentation
     * php artisan actions:docs --format=json --output=actions.json
     * @example
     * // Generate docs for specific action
     * php artisan actions:docs --action=App\Actions\ProcessOrder
     *
     * The generated documentation includes:
     * - Action class name and namespace
     * - All traits used
     * - Handle method parameters with types
     * - Dependencies
     * - Usage examples
     */
    public function handle(): int
    {
        $format = $this->option('format');
        $output = $this->option('output');
        $action = $this->option('action');

        if ($action) {
            $actions = collect([$action]);
        } else {
            $actions = ActionRegistry::discover();
        }

        $docs = match ($format) {
            'openapi' => $this->generateOpenApi($actions),
            'json' => $this->generateJson($actions),
            default => $this->generateMarkdown($actions),
        };

        if ($output) {
            file_put_contents($output, $docs);
            $this->info("Documentation written to: {$output}");
        } else {
            $this->line($docs);
        }

        return Command::SUCCESS;
    }

    protected function generateMarkdown($actions): string
    {
        $docs = "# Actions Documentation\n\n";
        $docs .= 'Generated: '.now()->toDateTimeString()."\n\n";
        $docs .= "Total Actions: {$actions->count()}\n\n";

        foreach ($actions as $actionClass) {
            $metadata = ActionRegistry::getMetadata($actionClass);
            $docs .= "## {$metadata['name']}\n\n";
            $docs .= "**Class:** `{$metadata['class']}`\n\n";

            if (! empty($metadata['traits'])) {
                $docs .= "**Traits:**\n";
                foreach ($metadata['traits'] as $trait) {
                    $docs .= "- `{$trait}`\n";
                }
                $docs .= "\n";
            }

            if (! empty($metadata['handle_params'])) {
                $docs .= "**Parameters:**\n";
                foreach ($metadata['handle_params'] as $param) {
                    $type = $param['type'] ?? 'mixed';
                    $optional = $param['optional'] ? ' (optional)' : '';
                    $docs .= "- `{$param['name']}`: {$type}{$optional}\n";
                }
                $docs .= "\n";
            }

            $dependencies = ActionRegistry::getDependencies($actionClass);
            if (! empty($dependencies)) {
                $docs .= "**Dependencies:**\n";
                foreach ($dependencies as $dep) {
                    $docs .= "- `{$dep}`\n";
                }
                $docs .= "\n";
            }

            $docs .= "---\n\n";
        }

        return $docs;
    }

    protected function generateOpenApi($actions): string
    {
        $openapi = [
            'openapi' => '3.0.0',
            'info' => [
                'title' => 'Actions API',
                'version' => '1.0.0',
                'description' => 'Auto-generated API documentation for actions',
            ],
            'paths' => [],
        ];

        foreach ($actions as $actionClass) {
            $metadata = ActionRegistry::getMetadata($actionClass);
            $path = '/actions/'.Str::kebab($metadata['name']);

            $openapi['paths'][$path] = [
                'post' => [
                    'summary' => $metadata['name'],
                    'operationId' => Str::camel($metadata['name']),
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => $this->buildParameterSchema($metadata['handle_params']),
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Success',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['type' => 'object'],
                                ],
                            ],
                        ],
                    ],
                ],
            ];
        }

        return json_encode($openapi, JSON_PRETTY_PRINT);
    }

    protected function generateJson($actions): string
    {
        $data = [];

        foreach ($actions as $actionClass) {
            $metadata = ActionRegistry::getMetadata($actionClass);
            $data[] = [
                'class' => $metadata['class'],
                'name' => $metadata['name'],
                'namespace' => $metadata['namespace'],
                'traits' => $metadata['traits'],
                'parameters' => $metadata['handle_params'],
                'dependencies' => ActionRegistry::getDependencies($actionClass),
            ];
        }

        return json_encode($data, JSON_PRETTY_PRINT);
    }

    protected function buildParameterSchema(array $params): array
    {
        $properties = [];

        foreach ($params as $param) {
            $type = $this->mapPhpTypeToJsonSchema($param['type'] ?? 'mixed');
            $properties[$param['name']] = [
                'type' => $type,
            ];
        }

        return $properties;
    }

    protected function mapPhpTypeToJsonSchema(?string $phpType): string
    {
        return match ($phpType) {
            'string' => 'string',
            'int', 'integer' => 'integer',
            'float', 'double' => 'number',
            'bool', 'boolean' => 'boolean',
            'array' => 'array',
            default => 'object',
        };
    }
}
