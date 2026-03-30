<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Services;

use App\Modules\TaskRunner\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * TemplateService handles template and blueprint management.
 * Provides immediate productivity boost through reusable task patterns.
 */
class TemplateService
{
    /**
     * Get templates for a specific task type.
     */
    public function getTemplatesForTaskType(string $taskType): array
    {
        try {
            $templates = DB::table('task_templates')
                ->where('task_type', $taskType)
                ->where('active', true)
                ->get()
                ->keyBy('name')
                ->toArray();

            return $this->formatTemplates($templates);

        } catch (\Exception $e) {
            Log::error('Failed to get templates for task type', [
                'task_type' => $taskType,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Save a new template.
     */
    public function saveTemplate(array $templateData): bool
    {
        try {
            $templateData['created_at'] = now();
            $templateData['updated_at'] = now();
            $templateData['active'] = true;

            DB::table('task_templates')->insert($templateData);

            Log::info('Template saved successfully', [
                'template_name' => $templateData['name'],
                'task_type' => $templateData['task_type'],
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Failed to save template', [
                'template_name' => $templateData['name'],
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Update an existing template.
     */
    public function updateTemplate(string $templateName, array $data): bool
    {
        try {
            $data['updated_at'] = now();

            DB::table('task_templates')
                ->where('name', $templateName)
                ->update($data);

            Log::info('Template updated successfully', [
                'template_name' => $templateName,
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Failed to update template', [
                'template_name' => $templateName,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Delete a template.
     */
    public function deleteTemplate(string $templateName): bool
    {
        try {
            DB::table('task_templates')
                ->where('name', $templateName)
                ->update(['active' => false]);

            Log::info('Template deleted successfully', [
                'template_name' => $templateName,
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Failed to delete template', [
                'template_name' => $templateName,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * List all templates.
     */
    public function listAllTemplates(): array
    {
        try {
            $templates = DB::table('task_templates')
                ->where('active', true)
                ->orderBy('created_at', 'desc')
                ->get()
                ->toArray();

            return $this->formatTemplates($templates);

        } catch (\Exception $e) {
            Log::error('Failed to list templates', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Search templates.
     */
    public function searchTemplates(string $query): array
    {
        try {
            $templates = DB::table('task_templates')
                ->where('active', true)
                ->where(function ($q) use ($query) {
                    $q->where('name', 'like', "%{$query}%")
                        ->orWhere('metadata->description', 'like', "%{$query}%")
                        ->orWhere('metadata->tags', 'like', "%{$query}%");
                })
                ->orderBy('created_at', 'desc')
                ->get()
                ->toArray();

            return $this->formatTemplates($templates);

        } catch (\Exception $e) {
            Log::error('Failed to search templates', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Get templates by category.
     */
    public function getTemplatesByCategory(string $category): array
    {
        try {
            $templates = DB::table('task_templates')
                ->where('active', true)
                ->where('metadata->category', $category)
                ->orderBy('created_at', 'desc')
                ->get()
                ->toArray();

            return $this->formatTemplates($templates);

        } catch (\Exception $e) {
            Log::error('Failed to get templates by category', [
                'category' => $category,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Import template from data.
     */
    public function importTemplate(array $templateData): bool
    {
        try {
            // Validate template data
            if (! isset($templateData['name']) || ! isset($templateData['task_type'])) {
                throw new \InvalidArgumentException('Template data must include name and task_type');
            }

            // Check if template already exists
            $existing = DB::table('task_templates')
                ->where('name', $templateData['name'])
                ->where('active', true)
                ->first();

            if ($existing) {
                // Update existing template
                return $this->updateTemplate($templateData['name'], $templateData);
            } else {
                // Create new template
                return $this->saveTemplate($templateData);
            }

        } catch (\Exception $e) {
            Log::error('Failed to import template', [
                'template_data' => $templateData,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Record template usage.
     */
    public function recordUsage(string $templateName, array $parameters = []): void
    {
        try {
            $usageData = [
                'template_name' => $templateName,
                'parameters' => json_encode($parameters),
                'used_at' => now(),
                'used_by' => auth()->id(),
            ];

            DB::table('template_usage')->insert($usageData);

            // Update usage count in template
            DB::table('task_templates')
                ->where('name', $templateName)
                ->increment('usage_count');

        } catch (\Exception $e) {
            Log::error('Failed to record template usage', [
                'template_name' => $templateName,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get template usage statistics.
     */
    public function getTemplateUsageStats(string $templateName): array
    {
        try {
            $usage = DB::table('template_usage')
                ->where('template_name', $templateName)
                ->orderBy('used_at', 'desc')
                ->get();

            $totalUsage = $usage->count();
            $recentUsage = $usage->where('used_at', '>=', now()->subDays(30))->count();

            return [
                'total_usage' => $totalUsage,
                'recent_usage' => $recentUsage,
                'usage_history' => $usage->take(10)->toArray(),
                'popular_parameters' => $this->getPopularParameters($templateName),
            ];

        } catch (\Exception $e) {
            Log::error('Failed to get template usage stats', [
                'template_name' => $templateName,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Get popular templates.
     */
    public function getPopularTemplates(int $limit = 10): array
    {
        try {
            $templates = DB::table('task_templates')
                ->where('active', true)
                ->orderBy('usage_count', 'desc')
                ->limit($limit)
                ->get()
                ->toArray();

            return $this->formatTemplates($templates);

        } catch (\Exception $e) {
            Log::error('Failed to get popular templates', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Get recent templates.
     */
    public function getRecentTemplates(int $limit = 10): array
    {
        try {
            $templates = DB::table('task_templates')
                ->where('active', true)
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get()
                ->toArray();

            return $this->formatTemplates($templates);

        } catch (\Exception $e) {
            Log::error('Failed to get recent templates', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Get template recommendations.
     */
    public function getTemplateRecommendations(?string $taskType = null): array
    {
        try {
            $query = DB::table('task_templates')->where('active', true);

            if ($taskType) {
                $query->where('task_type', $taskType);
            }

            $templates = $query->orderBy('usage_count', 'desc')
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get()
                ->toArray();

            return $this->formatTemplates($templates);

        } catch (\Exception $e) {
            Log::error('Failed to get template recommendations', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Get template version history.
     */
    public function getTemplateVersionHistory(string $templateName): array
    {
        try {
            $versions = DB::table('template_versions')
                ->where('template_name', $templateName)
                ->orderBy('created_at', 'desc')
                ->get()
                ->toArray();

            return array_map(function ($version) {
                $version->data = json_decode($version->data, true);

                return $version;
            }, $versions);

        } catch (\Exception $e) {
            Log::error('Failed to get template version history', [
                'template_name' => $templateName,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Restore template version.
     */
    public function restoreTemplateVersion(string $templateName, string $version): bool
    {
        try {
            $versionData = DB::table('template_versions')
                ->where('template_name', $templateName)
                ->where('version', $version)
                ->first();

            if (! $versionData) {
                throw new \InvalidArgumentException("Version '{$version}' not found for template '{$templateName}'");
            }

            $templateData = json_decode($versionData->data, true);
            $templateData['updated_at'] = now();

            return $this->updateTemplate($templateName, $templateData);

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
     * Create template version.
     */
    public function createTemplateVersion(string $templateName): bool
    {
        try {
            $template = DB::table('task_templates')
                ->where('name', $templateName)
                ->where('active', true)
                ->first();

            if (! $template) {
                throw new \InvalidArgumentException("Template '{$templateName}' not found");
            }

            $versionData = [
                'template_name' => $templateName,
                'version' => $this->generateVersionNumber($templateName),
                'data' => json_encode($template),
                'created_at' => now(),
                'created_by' => auth()->id(),
            ];

            DB::table('template_versions')->insert($versionData);

            return true;

        } catch (\Exception $e) {
            Log::error('Failed to create template version', [
                'template_name' => $templateName,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Generate built-in templates.
     */
    public function generateBuiltInTemplates(): array
    {
        return [
            'database_backup' => [
                'name' => 'database_backup',
                'task_type' => 'DatabaseTask',
                'category' => 'database',
                'description' => 'Create database backup with compression',
                'configuration' => [
                    'monitoring' => [
                        'enabled' => true,
                        'check_interval' => 60,
                    ],
                    'rollback' => [
                        'enabled' => true,
                        'timeout' => 300,
                    ],
                ],
                'parameters_schema' => [
                    'database_name' => [
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Database name to backup',
                    ],
                    'backup_path' => [
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Backup file path',
                        'default' => '/var/backups/database',
                    ],
                    'compress' => [
                        'type' => 'boolean',
                        'required' => false,
                        'description' => 'Compress backup file',
                        'default' => true,
                    ],
                ],
                'dependencies' => [
                    'mysqldump' => 'MySQL dump utility',
                    'gzip' => 'Compression utility',
                ],
            ],
            'file_cleanup' => [
                'name' => 'file_cleanup',
                'task_type' => 'FileTask',
                'category' => 'file_operations',
                'description' => 'Clean up old files and temporary data',
                'configuration' => [
                    'monitoring' => [
                        'enabled' => true,
                        'check_interval' => 30,
                    ],
                ],
                'parameters_schema' => [
                    'directory' => [
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Directory to clean',
                    ],
                    'age_days' => [
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Remove files older than X days',
                        'default' => 30,
                        'min' => 1,
                        'max' => 365,
                    ],
                    'file_pattern' => [
                        'type' => 'string',
                        'required' => false,
                        'description' => 'File pattern to match',
                        'default' => '*.tmp',
                    ],
                ],
            ],
            'system_monitoring' => [
                'name' => 'system_monitoring',
                'task_type' => 'SystemTask',
                'category' => 'monitoring',
                'description' => 'Monitor system resources and health',
                'configuration' => [
                    'monitoring' => [
                        'enabled' => true,
                        'check_interval' => 15,
                    ],
                    'analytics' => [
                        'enabled' => true,
                        'baseline' => [
                            'cpu_usage' => 0.8,
                            'memory_usage' => 0.9,
                            'disk_usage' => 0.85,
                        ],
                    ],
                ],
                'parameters_schema' => [
                    'check_cpu' => [
                        'type' => 'boolean',
                        'required' => false,
                        'description' => 'Check CPU usage',
                        'default' => true,
                    ],
                    'check_memory' => [
                        'type' => 'boolean',
                        'required' => false,
                        'description' => 'Check memory usage',
                        'default' => true,
                    ],
                    'check_disk' => [
                        'type' => 'boolean',
                        'required' => false,
                        'description' => 'Check disk usage',
                        'default' => true,
                    ],
                    'alert_threshold' => [
                        'type' => 'float',
                        'required' => false,
                        'description' => 'Alert threshold percentage',
                        'default' => 0.9,
                        'min' => 0.1,
                        'max' => 1.0,
                    ],
                ],
            ],
        ];
    }

    /**
     * Format templates for consistent output.
     */
    protected function formatTemplates(array $templates): array
    {
        return array_map(function ($template) {
            if (is_object($template)) {
                $template = (array) $template;
            }

            // Decode JSON fields
            if (isset($template['metadata'])) {
                $template['metadata'] = json_decode($template['metadata'], true) ?? [];
            }

            if (isset($template['configuration'])) {
                $template['configuration'] = json_decode($template['configuration'], true) ?? [];
            }

            if (isset($template['parameters_schema'])) {
                $template['parameters_schema'] = json_decode($template['parameters_schema'], true) ?? [];
            }

            if (isset($template['dependencies'])) {
                $template['dependencies'] = json_decode($template['dependencies'], true) ?? [];
            }

            return $template;
        }, $templates);
    }

    /**
     * Get popular parameters for a template.
     */
    protected function getPopularParameters(string $templateName): array
    {
        try {
            $parameters = DB::table('template_usage')
                ->where('template_name', $templateName)
                ->whereNotNull('parameters')
                ->get()
                ->pluck('parameters')
                ->map(function ($params) {
                    return json_decode($params, true) ?? [];
                })
                ->filter()
                ->toArray();

            // Count parameter frequency
            $frequency = [];
            foreach ($parameters as $params) {
                foreach ($params as $key => $value) {
                    if (! isset($frequency[$key])) {
                        $frequency[$key] = [];
                    }
                    $valueStr = is_array($value) ? json_encode($value) : (string) $value;
                    $frequency[$key][$valueStr] = ($frequency[$key][$valueStr] ?? 0) + 1;
                }
            }

            // Get most common values
            $popular = [];
            foreach ($frequency as $param => $values) {
                arsort($values);
                $popular[$param] = array_slice($values, 0, 3, true);
            }

            return $popular;

        } catch (\Exception $e) {
            Log::error('Failed to get popular parameters', [
                'template_name' => $templateName,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Generate version number for template.
     */
    protected function generateVersionNumber(string $templateName): string
    {
        try {
            $latestVersion = DB::table('template_versions')
                ->where('template_name', $templateName)
                ->orderBy('version', 'desc')
                ->first();

            if (! $latestVersion) {
                return '1.0.0';
            }

            $parts = explode('.', $latestVersion->version);
            $parts[2] = (int) $parts[2] + 1;

            return implode('.', $parts);

        } catch (\Exception $e) {
            return '1.0.0';
        }
    }
}
