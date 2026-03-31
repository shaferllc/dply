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
     */
    public function getAvailableTemplates(): array;

    /**
     * Get template by name.
     */
    public function getTemplate(string $templateName): ?array;

    /**
     * Create task from template.
     */
    public function createFromTemplate(string $templateName, array $parameters = []): self;

    /**
     * Save current task as template.
     */
    public function saveAsTemplate(string $templateName, array $metadata = []): bool;

    /**
     * Update existing template.
     */
    public function updateTemplate(string $templateName, array $data): bool;

    /**
     * Delete template.
     */
    public function deleteTemplate(string $templateName): bool;

    /**
     * Get template metadata.
     */
    public function getTemplateMetadata(string $templateName): array;

    /**
     * Validate template parameters.
     */
    public function validateTemplateParameters(string $templateName, array $parameters): array;

    /**
     * Get template parameters schema.
     */
    public function getTemplateParametersSchema(string $templateName): array;

    /**
     * List all templates.
     */
    public function listTemplates(): array;

    /**
     * Search templates.
     */
    public function searchTemplates(string $query): array;

    /**
     * Get template categories.
     */
    public function getTemplateCategories(): array;

    /**
     * Get templates by category.
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
     */
    public function getTemplateUsageStats(string $templateName): array;

    /**
     * Get popular templates.
     */
    public function getPopularTemplates(int $limit = 10): array;

    /**
     * Get recent templates.
     */
    public function getRecentTemplates(int $limit = 10): array;

    /**
     * Get template recommendations.
     */
    public function getTemplateRecommendations(): array;

    /**
     * Clone template.
     */
    public function cloneTemplate(string $sourceTemplate, string $newTemplateName): bool;

    /**
     * Get template version history.
     */
    public function getTemplateVersionHistory(string $templateName): array;

    /**
     * Restore template version.
     */
    public function restoreTemplateVersion(string $templateName, string $version): bool;

    /**
     * Get template dependencies.
     */
    public function getTemplateDependencies(string $templateName): array;

    /**
     * Check template compatibility.
     */
    public function checkTemplateCompatibility(string $templateName): array;
}
