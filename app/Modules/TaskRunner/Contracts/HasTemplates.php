<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Contracts;

use App\Modules\TaskRunner\Models\Task;

/**
 * HasTemplates contract for tasks that support templates and blueprints.
 * Provides immediate productivity boost through reusable task patterns.
 */
interface HasTemplates
{
    /**
     * Check if templates are enabled for this task.
     */
    public function isTemplatesEnabled(): bool;

    /**
     * Get available templates for this task type.
     * @return array<string, mixed>
     */
    public function getAvailableTemplates(): array;

    /**
     * Get template by name.
     * @return array<string, mixed>
     */
    public function getTemplate(string $templateName): ?array;

    /**
     * Create task from template.
     * @param  array<string, mixed> $parameters
     */
    public function createFromTemplate(string $templateName, array $parameters = []): self;

    /**
     * Save current task as template.
     * @param  array<string, mixed> $metadata
     */
    public function saveAsTemplate(string $templateName, array $metadata = []): bool;

    /**
     * Update existing template.
     * @param  array<string, mixed> $data
     */
    public function updateTemplate(string $templateName, array $data): bool;

    /**
     * Delete template.
     */
    public function deleteTemplate(string $templateName): bool;

    /**
     * Get template metadata.
     * @return array<string, mixed>
     */
    public function getTemplateMetadata(string $templateName): array;

    /**
     * Validate template parameters.
     * @param  array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    public function validateTemplateParameters(string $templateName, array $parameters): array;

    /**
     * Get template parameters schema.
     * @return array<string, mixed>
     */
    public function getTemplateParametersSchema(string $templateName): array;

    /**
     * List all templates.
     * @return array<string, mixed>
     */
    public function listTemplates(): array;

    /**
     * Search templates.
     * @return array<string, mixed>
     */
    public function searchTemplates(string $query): array;

    /**
     * Get template categories.
     * @return array<string, mixed>
     */
    public function getTemplateCategories(): array;

    /**
     * Get templates by category.
     * @return array<string, mixed>
     */
    public function getTemplatesByCategory(string $category): array;

    /**
     * Import template from file.
     */
    public function importTemplate(string $filePath): bool;

    /**
     * Export template to file.
     */
    public function exportTemplate(string $templateName, string $format = 'json'): string;

    /**
     * Get template usage statistics.
     * @return array<string, mixed>
     */
    public function getTemplateUsageStats(string $templateName): array;

    /**
     * Get popular templates.
     * @return array<string, mixed>
     */
    public function getPopularTemplates(int $limit = 10): array;

    /**
     * Get recent templates.
     * @return array<string, mixed>
     */
    public function getRecentTemplates(int $limit = 10): array;

    /**
     * Get template recommendations.
     * @return array<string, mixed>
     */
    public function getTemplateRecommendations(): array;

    /**
     * Clone template.
     */
    public function cloneTemplate(string $sourceTemplate, string $newTemplateName): bool;

    /**
     * Get template version history.
     * @return array<string, mixed>
     */
    public function getTemplateVersionHistory(string $templateName): array;

    /**
     * Restore template version.
     */
    public function restoreTemplateVersion(string $templateName, string $version): bool;

    /**
     * Get template dependencies.
     * @return array<string, mixed>
     */
    public function getTemplateDependencies(string $templateName): array;

    /**
     * Check template compatibility.
     * @return array<string, mixed>
     */
    public function checkTemplateCompatibility(string $templateName): array;
}
