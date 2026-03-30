<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Traits;

use App\Modules\TaskRunner\Services\TemplateService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * HandlesTemplates trait provides comprehensive template and blueprint functionality.
 * Enables immediate productivity boost through reusable task patterns and rapid task generation.
 */
trait HandlesTemplates
{
    /**
     * Template configuration properties.
     */
    protected bool $templatesEnabled = true;

    protected array $templates = [];

    protected array $templateCategories = [];

    protected array $templateMetadata = [];

    protected array $templateParameters = [];

    protected array $templateDependencies = [];

    /**
     * Check if templates are enabled for this task.
     */
    public function isTemplatesEnabled(): bool
    {
        return $this->templatesEnabled;
    }

    /**
     * Get available templates for this task type.
     */
    public function getAvailableTemplates(): array
    {
        $cacheKey = "task_templates_{$this->getTaskType()}";
        $templates = Cache::get($cacheKey);

        if ($templates === null) {
            $templateService = app(TemplateService::class);
            $templates = $templateService->getTemplatesForTaskType($this->getTaskType());
            Cache::put($cacheKey, $templates, now()->addHours(1));
        }

        return array_merge($templates, $this->templates);
    }

    /**
     * Get template by name.
     */
    public function getTemplate(string $templateName): ?array
    {
        $templates = $this->getAvailableTemplates();

        return $templates[$templateName] ?? null;
    }

    /**
     * Create task from template.
     */
    public function createFromTemplate(string $templateName, array $parameters = []): static
    {
        $template = $this->getTemplate($templateName);

        if (! $template) {
            throw new \InvalidArgumentException("Template '{$templateName}' not found");
        }

        // Validate parameters
        $validation = $this->validateTemplateParameters($templateName, $parameters);
        if (! empty($validation['errors'])) {
            throw new \InvalidArgumentException('Invalid template parameters: '.implode(', ', $validation['errors']));
        }

        // Apply template configuration
        $this->applyTemplateConfiguration($template, $parameters);

        // Record template usage
        $this->recordTemplateUsage($templateName, $parameters);

        return $this;
    }

    /**
     * Save current task as template.
     */
    public function saveAsTemplate(string $templateName, array $metadata = []): bool
    {
        try {
            $templateData = [
                'name' => $templateName,
                'task_type' => $this->getTaskType(),
                'configuration' => $this->getTaskConfiguration(),
                'metadata' => array_merge([
                    'created_at' => now()->toISOString(),
                    'created_by' => Auth::id(),
                    'version' => '1.0.0',
                    'description' => $metadata['description'] ?? '',
                    'category' => $metadata['category'] ?? 'custom',
                    'tags' => $metadata['tags'] ?? [],
                ], $metadata),
                'parameters_schema' => $this->generateParametersSchema(),
                'dependencies' => $this->getTaskDependencies(),
            ];

            $templateService = app(TemplateService::class);
            $success = $templateService->saveTemplate($templateData);

            if ($success) {
                $this->templates[$templateName] = $templateData;
                $this->clearTemplateCache();
            }

            return $success;

        } catch (\Exception $e) {
            Log::error('Failed to save template', [
                'template_name' => $templateName,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Update existing template.
     */
    public function updateTemplate(string $templateName, array $data): bool
    {
        try {
            $templateService = app(TemplateService::class);
            $success = $templateService->updateTemplate($templateName, $data);

            if ($success) {
                $this->clearTemplateCache();
            }

            return $success;

        } catch (\Exception $e) {
            Log::error('Failed to update template', [
                'template_name' => $templateName,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Delete template.
     */
    public function deleteTemplate(string $templateName): bool
    {
        try {
            $templateService = app(TemplateService::class);
            $success = $templateService->deleteTemplate($templateName);

            if ($success) {
                unset($this->templates[$templateName]);
                $this->clearTemplateCache();
            }

            return $success;

        } catch (\Exception $e) {
            Log::error('Failed to delete template', [
                'template_name' => $templateName,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get template metadata.
     */
    public function getTemplateMetadata(string $templateName): array
    {
        $template = $this->getTemplate($templateName);

        return $template['metadata'] ?? [];
    }

    /**
     * Validate template parameters.
     */
    public function validateTemplateParameters(string $templateName, array $parameters): array
    {
        $template = $this->getTemplate($templateName);
        if (! $template) {
            return ['errors' => ["Template '{$templateName}' not found"]];
        }

        $schema = $template['parameters_schema'] ?? [];
        $errors = [];

        foreach ($schema as $paramName => $paramConfig) {
            $value = $parameters[$paramName] ?? null;

            // Check required parameters
            if (($paramConfig['required'] ?? false) && $value === null) {
                $errors[] = "Required parameter '{$paramName}' is missing";

                continue;
            }

            // Skip validation if value is null and not required
            if ($value === null) {
                continue;
            }

            // Type validation
            $type = $paramConfig['type'] ?? 'string';
            if (! $this->validateParameterType($value, $type)) {
                $errors[] = "Parameter '{$paramName}' must be of type '{$type}'";
            }

            // Range validation
            if (isset($paramConfig['min']) && $value < $paramConfig['min']) {
                $errors[] = "Parameter '{$paramName}' must be at least {$paramConfig['min']}";
            }

            if (isset($paramConfig['max']) && $value > $paramConfig['max']) {
                $errors[] = "Parameter '{$paramName}' must be at most {$paramConfig['max']}";
            }

            // Pattern validation
            if (isset($paramConfig['pattern']) && ! preg_match($paramConfig['pattern'], $value)) {
                $errors[] = "Parameter '{$paramName}' does not match required pattern";
            }

            // Enum validation
            if (isset($paramConfig['enum']) && ! in_array($value, $paramConfig['enum'])) {
                $errors[] = "Parameter '{$paramName}' must be one of: ".implode(', ', $paramConfig['enum']);
            }
        }

        return ['errors' => $errors, 'valid' => empty($errors)];
    }

    /**
     * Get template parameters schema.
     */
    public function getTemplateParametersSchema(string $templateName): array
    {
        $template = $this->getTemplate($templateName);

        return $template['parameters_schema'] ?? [];
    }

    /**
     * List all templates.
     */
    public function listTemplates(): array
    {
        $templateService = app(TemplateService::class);

        return $templateService->listAllTemplates();
    }

    /**
     * Search templates.
     */
    public function searchTemplates(string $query): array
    {
        $templateService = app(TemplateService::class);

        return $templateService->searchTemplates($query);
    }

    /**
     * Get template categories.
     */
    public function getTemplateCategories(): array
    {
        return array_merge([
            'database' => 'Database Operations',
            'file_operations' => 'File Operations',
            'system' => 'System Administration',
            'deployment' => 'Deployment',
            'backup' => 'Backup & Recovery',
            'monitoring' => 'Monitoring & Logging',
            'security' => 'Security',
            'custom' => 'Custom Templates',
        ], $this->templateCategories);
    }

    /**
     * Get templates by category.
     */
    public function getTemplatesByCategory(string $category): array
    {
        $templateService = app(TemplateService::class);

        return $templateService->getTemplatesByCategory($category);
    }

    /**
     * Import template from file.
     */
    public function importTemplate(string $filePath): bool
    {
        try {
            if (! file_exists($filePath)) {
                throw new \InvalidArgumentException("Template file not found: {$filePath}");
            }

            $content = file_get_contents($filePath);
            $templateData = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \InvalidArgumentException('Invalid JSON format in template file');
            }

            $templateService = app(TemplateService::class);
            $success = $templateService->importTemplate($templateData);

            if ($success) {
                $this->clearTemplateCache();
            }

            return $success;

        } catch (\Exception $e) {
            Log::error('Failed to import template', [
                'file_path' => $filePath,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Export template to file.
     */
    public function exportTemplate(string $templateName, string $format = 'json'): string
    {
        $template = $this->getTemplate($templateName);

        if (! $template) {
            throw new \InvalidArgumentException("Template '{$templateName}' not found");
        }

        return match ($format) {
            'json' => json_encode($template, JSON_PRETTY_PRINT),
            'yaml' => $this->convertToYaml($template),
            'xml' => $this->convertToXml($template),
            default => json_encode($template, JSON_PRETTY_PRINT),
        };
    }

    /**
     * Get template usage statistics.
     */
    public function getTemplateUsageStats(string $templateName): array
    {
        $templateService = app(TemplateService::class);

        return $templateService->getTemplateUsageStats($templateName);
    }

    /**
     * Get popular templates.
     */
    public function getPopularTemplates(int $limit = 10): array
    {
        $templateService = app(TemplateService::class);

        return $templateService->getPopularTemplates($limit);
    }

    /**
     * Get recent templates.
     */
    public function getRecentTemplates(int $limit = 10): array
    {
        $templateService = app(TemplateService::class);

        return $templateService->getRecentTemplates($limit);
    }

    /**
     * Get template recommendations.
     */
    public function getTemplateRecommendations(): array
    {
        $templateService = app(TemplateService::class);

        return $templateService->getTemplateRecommendations($this->getTaskType());
    }

    /**
     * Clone template.
     */
    public function cloneTemplate(string $sourceTemplate, string $newTemplateName): bool
    {
        try {
            $template = $this->getTemplate($sourceTemplate);

            if (! $template) {
                throw new \InvalidArgumentException("Source template '{$sourceTemplate}' not found");
            }

            $clonedTemplate = $template;
            $clonedTemplate['name'] = $newTemplateName;
            $clonedTemplate['metadata']['created_at'] = now()->toISOString();
            $clonedTemplate['metadata']['created_by'] = Auth::id();
            $clonedTemplate['metadata']['cloned_from'] = $sourceTemplate;

            $templateService = app(TemplateService::class);
            $success = $templateService->saveTemplate($clonedTemplate);

            if ($success) {
                $this->clearTemplateCache();
            }

            return $success;

        } catch (\Exception $e) {
            Log::error('Failed to clone template', [
                'source_template' => $sourceTemplate,
                'new_template' => $newTemplateName,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get template version history.
     */
    public function getTemplateVersionHistory(string $templateName): array
    {
        $templateService = app(TemplateService::class);

        return $templateService->getTemplateVersionHistory($templateName);
    }

    /**
     * Restore template version.
     */
    public function restoreTemplateVersion(string $templateName, string $version): bool
    {
        try {
            $templateService = app(TemplateService::class);
            $success = $templateService->restoreTemplateVersion($templateName, $version);

            if ($success) {
                $this->clearTemplateCache();
            }

            return $success;

        } catch (\Exception $e) {
            Log::error('Failed to restore template version', [
                'template_name' => $templateName,
                'version' => $version,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get template dependencies.
     */
    public function getTemplateDependencies(string $templateName): array
    {
        $template = $this->getTemplate($templateName);

        return $template['dependencies'] ?? [];
    }

    /**
     * Check template compatibility.
     */
    public function checkTemplateCompatibility(string $templateName): array
    {
        $template = $this->getTemplate($templateName);
        if (! $template) {
            return ['compatible' => false, 'errors' => ['Template not found']];
        }

        $dependencies = $template['dependencies'] ?? [];
        $errors = [];

        foreach ($dependencies as $dependency) {
            if (! $this->checkDependency($dependency)) {
                $errors[] = "Missing dependency: {$dependency}";
            }
        }

        return [
            'compatible' => empty($errors),
            'errors' => $errors,
            'dependencies' => $dependencies,
        ];
    }

    /**
     * Set templates configuration.
     */
    public function setTemplatesConfig(array $config): self
    {
        $this->templatesEnabled = $config['enabled'] ?? true;
        $this->templateCategories = $config['categories'] ?? [];

        return $this;
    }

    /**
     * Enable templates for this task.
     */
    public function enableTemplates(): self
    {
        $this->templatesEnabled = true;

        return $this;
    }

    /**
     * Disable templates for this task.
     */
    public function disableTemplates(): self
    {
        $this->templatesEnabled = false;

        return $this;
    }

    /**
     * Add template category.
     */
    public function addTemplateCategory(string $key, string $name): self
    {
        $this->templateCategories[$key] = $name;

        return $this;
    }

    /**
     * Get task type for template categorization.
     */
    protected function getTaskType(): string
    {
        return class_basename($this);
    }

    /**
     * Get task configuration for template saving.
     */
    protected function getTaskConfiguration(): array
    {
        return [
            'class' => get_class($this),
            'properties' => $this->getTaskProperties(),
            'methods' => $this->getTaskMethods(),
        ];
    }

    /**
     * Get task properties for template saving.
     */
    protected function getTaskProperties(): array
    {
        $reflection = new \ReflectionClass($this);
        $properties = [];

        foreach ($reflection->getProperties() as $property) {
            if ($property->isPublic() || $property->isProtected()) {
                $property->setAccessible(true);
                $properties[$property->getName()] = $property->getValue($this);
            }
        }

        return $properties;
    }

    /**
     * Get task methods for template saving.
     */
    protected function getTaskMethods(): array
    {
        $reflection = new \ReflectionClass($this);
        $methods = [];

        foreach ($reflection->getMethods() as $method) {
            if ($method->isPublic() && ! $method->isConstructor()) {
                $methods[] = $method->getName();
            }
        }

        return $methods;
    }

    /**
     * Apply template configuration to task.
     */
    protected function applyTemplateConfiguration(array $template, array $parameters): void
    {
        $configuration = $template['configuration'] ?? [];

        // Apply properties
        if (isset($configuration['properties'])) {
            foreach ($configuration['properties'] as $property => $value) {
                if (property_exists($this, $property)) {
                    $this->$property = $this->interpolateParameters($value, $parameters);
                }
            }
        }

        // Apply configuration
        if (isset($template['configuration'])) {
            $this->applyConfiguration($template['configuration'], $parameters);
        }
    }

    /**
     * Interpolate parameters in template values.
     */
    protected function interpolateParameters($value, array $parameters): mixed
    {
        if (is_string($value)) {
            foreach ($parameters as $key => $paramValue) {
                $value = str_replace("{{$key}}", $paramValue, $value);
            }
        }

        return $value;
    }

    /**
     * Apply configuration to task.
     */
    protected function applyConfiguration(array $configuration, array $parameters): void
    {
        // Apply monitoring configuration
        if (isset($configuration['monitoring'])) {
            $this->setMonitoringConfiguration($configuration['monitoring']);
        }

        // Apply analytics configuration
        if (isset($configuration['analytics'])) {
            $this->setAnalyticsConfiguration($configuration['analytics']);
        }

        // Apply rollback configuration
        if (isset($configuration['rollback'])) {
            $this->setRollbackConfiguration($configuration['rollback']);
        }

        // Apply callback configuration
        if (isset($configuration['callbacks'])) {
            $this->setCallbackConfiguration($configuration['callbacks']);
        }
    }

    /**
     * Generate parameters schema from task properties.
     */
    protected function generateParametersSchema(): array
    {
        $schema = [];

        // Generate schema based on task properties and methods
        $reflection = new \ReflectionClass($this);

        foreach ($reflection->getProperties() as $property) {
            if ($property->isPublic() || $property->isProtected()) {
                $schema[$property->getName()] = [
                    'type' => $this->getPropertyType($property),
                    'required' => false,
                    'description' => $this->getPropertyDescription($property),
                ];
            }
        }

        return $schema;
    }

    /**
     * Get property type for schema generation.
     */
    protected function getPropertyType(\ReflectionProperty $property): string
    {
        $type = $property->getType();

        if ($type instanceof \ReflectionNamedType) {
            return $type->getName();
        }

        return 'mixed';
    }

    /**
     * Get property description for schema generation.
     */
    protected function getPropertyDescription(\ReflectionProperty $property): string
    {
        $docComment = $property->getDocComment();

        if ($docComment) {
            // Extract description from doc comment
            preg_match('/\*\s*(.+)/', $docComment, $matches);

            return $matches[1] ?? '';
        }

        return '';
    }

    /**
     * Get task dependencies.
     */
    protected function getTaskDependencies(): array
    {
        return [
            'php_version' => '8.1',
            'laravel_version' => '10.0',
            'extensions' => ['json', 'mbstring'],
        ];
    }

    /**
     * Record template usage.
     */
    protected function recordTemplateUsage(string $templateName, array $parameters): void
    {
        $templateService = app(TemplateService::class);
        $templateService->recordUsage($templateName, $parameters);
    }

    /**
     * Clear template cache.
     */
    protected function clearTemplateCache(): void
    {
        $cacheKey = "task_templates_{$this->getTaskType()}";
        Cache::forget($cacheKey);
    }

    /**
     * Validate parameter type.
     */
    protected function validateParameterType($value, string $type): bool
    {
        return match ($type) {
            'string' => is_string($value),
            'integer', 'int' => is_int($value),
            'float', 'double' => is_float($value),
            'boolean', 'bool' => is_bool($value),
            'array' => is_array($value),
            'object' => is_object($value),
            default => true,
        };
    }

    /**
     * Check dependency availability.
     */
    protected function checkDependency(string $dependency): bool
    {
        // Implement dependency checking logic
        return true;
    }

    /**
     * Convert template to YAML.
     */
    protected function convertToYaml(array $template): string
    {
        // Convert template to YAML format
        return ''; // Placeholder
    }
}
