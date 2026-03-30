# TaskRunner Templates & Blueprints Guide

The TaskRunner module now includes comprehensive templates and blueprints functionality to provide immediate productivity boost. This guide covers all aspects of the template system for rapid task creation and reusable patterns.

## Overview

The templates and blueprints system provides immediate productivity boost through reusable task patterns, rapid task generation, and standardized configurations. It enables developers to create tasks quickly using pre-built templates and custom blueprints.

## Core Components

### 1. HasTemplates Contract
The `HasTemplates` contract defines the interface for tasks that support templates and blueprints:

```php
interface HasTemplates
{
    public function isTemplatesEnabled(): bool;
    public function getAvailableTemplates(): array;
    public function getTemplate(string $templateName): ?array;
    public function createFromTemplate(string $templateName, array $parameters = []): self;
    public function saveAsTemplate(string $templateName, array $metadata = []): bool;
    public function updateTemplate(string $templateName, array $data): bool;
    public function deleteTemplate(string $templateName): bool;
    public function getTemplateMetadata(string $templateName): array;
    public function validateTemplateParameters(string $templateName, array $parameters): array;
    public function getTemplateParametersSchema(string $templateName): array;
    public function listTemplates(): array;
    public function searchTemplates(string $query): array;
    public function getTemplateCategories(): array;
    public function getTemplatesByCategory(string $category): array;
    public function importTemplate(string $filePath): bool;
    public function exportTemplate(string $templateName, string $format = 'json'): string;
    public function getTemplateUsageStats(string $templateName): array;
    public function getPopularTemplates(int $limit = 10): array;
    public function getRecentTemplates(int $limit = 10): array;
    public function getTemplateRecommendations(): array;
    public function cloneTemplate(string $sourceTemplate, string $newTemplateName): bool;
    public function getTemplateVersionHistory(string $templateName): array;
    public function restoreTemplateVersion(string $templateName, string $version): bool;
    public function getTemplateDependencies(string $templateName): array;
    public function checkTemplateCompatibility(string $templateName): array;
}
```

### 2. HandlesTemplates Trait
The `HandlesTemplates` trait provides comprehensive template functionality:

```php
trait HandlesTemplates
{
    // Template support
    public function isTemplatesEnabled(): bool;
    public function getAvailableTemplates(): array;
    public function getTemplate(string $templateName): ?array;
    public function createFromTemplate(string $templateName, array $parameters = []): self;
    
    // Template management
    public function saveAsTemplate(string $templateName, array $metadata = []): bool;
    public function updateTemplate(string $templateName, array $data): bool;
    public function deleteTemplate(string $templateName): bool;
    public function cloneTemplate(string $sourceTemplate, string $newTemplateName): bool;
    
    // Template discovery
    public function listTemplates(): array;
    public function searchTemplates(string $query): array;
    public function getTemplatesByCategory(string $category): array;
    public function getPopularTemplates(int $limit = 10): array;
    public function getRecentTemplates(int $limit = 10): array;
    public function getTemplateRecommendations(): array;
    
    // Template validation
    public function validateTemplateParameters(string $templateName, array $parameters): array;
    public function getTemplateParametersSchema(string $templateName): array;
    public function checkTemplateCompatibility(string $templateName): array;
    
    // Template import/export
    public function importTemplate(string $filePath): bool;
    public function exportTemplate(string $templateName, string $format = 'json'): string;
    
    // Configuration
    public function setTemplatesConfig(array $config): self;
    public function enableTemplates(): self;
    public function disableTemplates(): self;
    public function addTemplateCategory(string $key, string $name): self;
}
```

### 3. TemplateService
The `TemplateService` handles template operations and management:

```php
class TemplateService
{
    public function getTemplatesForTaskType(string $taskType): array;
    public function saveTemplate(array $templateData): bool;
    public function updateTemplate(string $templateName, array $data): bool;
    public function deleteTemplate(string $templateName): bool;
    public function listAllTemplates(): array;
    public function searchTemplates(string $query): array;
    public function getTemplatesByCategory(string $category): array;
    public function importTemplate(array $templateData): bool;
    public function recordUsage(string $templateName, array $parameters = []): void;
    public function getTemplateUsageStats(string $templateName): array;
    public function getPopularTemplates(int $limit = 10): array;
    public function getRecentTemplates(int $limit = 10): array;
    public function getTemplateRecommendations(string $taskType = null): array;
    public function getTemplateVersionHistory(string $templateName): array;
    public function restoreTemplateVersion(string $templateName, string $version): bool;
    public function createTemplateVersion(string $templateName): bool;
    public function generateBuiltInTemplates(): array;
}
```

## Usage Examples

### Basic Task with Templates

```php
<?php

namespace App\Modules\TaskRunner;

use App\Modules\TaskRunner\BaseTask;

class TemplateTask extends BaseTask
{
    public function __construct()
    {
        parent::__construct();
        
        // Configure templates
        $this->setTemplatesConfig([
            'enabled' => true,
            'categories' => [
                'custom' => 'Custom Templates',
                'database' => 'Database Operations',
            ],
        ]);
    }

    public function render(): string
    {
        // Create task from template
        $this->createFromTemplate('database_backup', [
            'database_name' => 'myapp',
            'backup_path' => '/var/backups',
            'compress' => true,
        ]);

        $script = "echo 'Task created from template'";
        return $script;
    }
}
```

### Advanced Task with Template Management

```php
<?php

namespace App\Modules\TaskRunner;

use App\Modules\TaskRunner\BaseTask;

class AdvancedTemplateTask extends BaseTask
{
    public function __construct()
    {
        parent::__construct();
        
        $this->setTemplatesConfig([
            'enabled' => true,
            'categories' => [
                'production' => 'Production Tasks',
                'development' => 'Development Tasks',
                'testing' => 'Testing Tasks',
            ],
        ]);

        // Add custom template categories
        $this->addTemplateCategory('deployment', 'Deployment Tasks');
        $this->addTemplateCategory('monitoring', 'Monitoring Tasks');
    }

    public function render(): string
    {
        // Save current task as template
        $this->saveAsTemplate('production_deployment', [
            'description' => 'Production deployment task with monitoring and rollback',
            'category' => 'production',
            'tags' => ['deployment', 'production', 'monitoring'],
            'version' => '1.0.0',
        ]);

        // Create task from popular template
        $popularTemplates = $this->getPopularTemplates(5);
        if (!empty($popularTemplates)) {
            $template = $popularTemplates[0];
            $this->createFromTemplate($template['name'], [
                'environment' => 'production',
                'auto_rollback' => true,
            ]);
        }

        $script = "echo 'Advanced template task executed'";
        return $script;
    }

    public function createProductionTask(): self
    {
        // Create from production template
        return $this->createFromTemplate('production_ready', [
            'monitoring_enabled' => true,
            'analytics_enabled' => true,
            'rollback_enabled' => true,
            'alert_threshold' => 0.8,
        ]);
    }

    public function createDevelopmentTask(): self
    {
        // Create from development template
        return $this->createFromTemplate('development_ready', [
            'monitoring_enabled' => false,
            'analytics_enabled' => true,
            'rollback_enabled' => false,
            'debug_mode' => true,
        ]);
    }
}
```

### Template with Parameter Validation

```php
<?php

namespace App\Modules\TaskRunner;

use App\Modules\TaskRunner\BaseTask;

class ValidatedTemplateTask extends BaseTask
{
    public function __construct()
    {
        parent::__construct();
        
        // Save template with parameter schema
        $this->saveAsTemplate('validated_task', [
            'description' => 'Task with parameter validation',
            'category' => 'validation',
            'parameters_schema' => [
                'database_name' => [
                    'type' => 'string',
                    'required' => true,
                    'description' => 'Database name',
                    'pattern' => '/^[a-zA-Z0-9_]+$/',
                ],
                'backup_retention' => [
                    'type' => 'integer',
                    'required' => false,
                    'description' => 'Backup retention days',
                    'default' => 30,
                    'min' => 1,
                    'max' => 365,
                ],
                'compression_level' => [
                    'type' => 'integer',
                    'required' => false,
                    'description' => 'Compression level',
                    'default' => 6,
                    'min' => 1,
                    'max' => 9,
                ],
                'encrypt_backup' => [
                    'type' => 'boolean',
                    'required' => false,
                    'description' => 'Encrypt backup files',
                    'default' => false,
                ],
            ],
        ]);
    }

    public function render(): string
    {
        // Create task with validated parameters
        $this->createFromTemplate('validated_task', [
            'database_name' => 'production_db',
            'backup_retention' => 90,
            'compression_level' => 8,
            'encrypt_backup' => true,
        ]);

        $script = "echo 'Validated template task executed'";
        return $script;
    }
}
```

## Built-in Templates

### Database Templates

```php
// Database backup template
$databaseBackupTemplate = [
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
];

// Database optimization template
$databaseOptimizationTemplate = [
    'name' => 'database_optimization',
    'task_type' => 'DatabaseTask',
    'category' => 'database',
    'description' => 'Optimize database performance',
    'configuration' => [
        'monitoring' => [
            'enabled' => true,
            'check_interval' => 30,
        ],
        'analytics' => [
            'enabled' => true,
            'baseline' => [
                'execution_time' => 120,
                'memory_usage' => 100 * 1024 * 1024,
            ],
        ],
    ],
    'parameters_schema' => [
        'tables' => [
            'type' => 'array',
            'required' => false,
            'description' => 'Tables to optimize',
            'default' => ['users', 'posts', 'comments'],
        ],
        'analyze_tables' => [
            'type' => 'boolean',
            'required' => false,
            'description' => 'Analyze table statistics',
            'default' => true,
        ],
        'repair_tables' => [
            'type' => 'boolean',
            'required' => false,
            'description' => 'Repair corrupted tables',
            'default' => false,
        ],
    ],
];
```

### File Operation Templates

```php
// File cleanup template
$fileCleanupTemplate = [
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
        'dry_run' => [
            'type' => 'boolean',
            'required' => false,
            'description' => 'Show what would be deleted without actually deleting',
            'default' => false,
        ],
    ],
];

// File backup template
$fileBackupTemplate = [
    'name' => 'file_backup',
    'task_type' => 'FileTask',
    'category' => 'file_operations',
    'description' => 'Create file backup with versioning',
    'configuration' => [
        'monitoring' => [
            'enabled' => true,
            'check_interval' => 60,
        ],
        'rollback' => [
            'enabled' => true,
            'timeout' => 600,
        ],
    ],
    'parameters_schema' => [
        'source_path' => [
            'type' => 'string',
            'required' => true,
            'description' => 'Source directory to backup',
        ],
        'backup_path' => [
            'type' => 'string',
            'required' => true,
            'description' => 'Backup destination directory',
        ],
        'include_pattern' => [
            'type' => 'string',
            'required' => false,
            'description' => 'Include files matching pattern',
            'default' => '*',
        ],
        'exclude_pattern' => [
            'type' => 'string',
            'required' => false,
            'description' => 'Exclude files matching pattern',
            'default' => '*.tmp',
        ],
        'compress' => [
            'type' => 'boolean',
            'required' => false,
            'description' => 'Compress backup',
            'default' => true,
        ],
    ],
];
```

### System Templates

```php
// System monitoring template
$systemMonitoringTemplate = [
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
        'check_network' => [
            'type' => 'boolean',
            'required' => false,
            'description' => 'Check network connectivity',
            'default' => false,
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
];

// System maintenance template
$systemMaintenanceTemplate = [
    'name' => 'system_maintenance',
    'task_type' => 'SystemTask',
    'category' => 'maintenance',
    'description' => 'Perform system maintenance tasks',
    'configuration' => [
        'monitoring' => [
            'enabled' => true,
            'check_interval' => 30,
        ],
        'rollback' => [
            'enabled' => true,
            'timeout' => 300,
        ],
    ],
    'parameters_schema' => [
        'update_packages' => [
            'type' => 'boolean',
            'required' => false,
            'description' => 'Update system packages',
            'default' => false,
        ],
        'clean_logs' => [
            'type' => 'boolean',
            'required' => false,
            'description' => 'Clean old log files',
            'default' => true,
        ],
        'optimize_database' => [
            'type' => 'boolean',
            'required' => false,
            'description' => 'Optimize database',
            'default' => false,
        ],
        'restart_services' => [
            'type' => 'array',
            'required' => false,
            'description' => 'Services to restart',
            'default' => [],
        ],
    ],
];
```

## Template Management

### Creating Templates

```php
// Save current task as template
$task->saveAsTemplate('my_custom_template', [
    'description' => 'Custom template for specific use case',
    'category' => 'custom',
    'tags' => ['custom', 'production', 'monitoring'],
    'version' => '1.0.0',
    'author' => 'John Doe',
    'documentation' => 'https://docs.example.com/templates/my-custom-template',
]);
```

### Using Templates

```php
// Create task from template
$task->createFromTemplate('database_backup', [
    'database_name' => 'production_db',
    'backup_path' => '/var/backups/production',
    'compress' => true,
    'encrypt' => true,
]);

// Create task with validation
$validation = $task->validateTemplateParameters('database_backup', $parameters);
if ($validation['valid']) {
    $task->createFromTemplate('database_backup', $parameters);
} else {
    throw new \InvalidArgumentException('Invalid parameters: ' . implode(', ', $validation['errors']));
}
```

### Template Discovery

```php
// List all templates
$allTemplates = $task->listTemplates();

// Search templates
$searchResults = $task->searchTemplates('database');

// Get templates by category
$databaseTemplates = $task->getTemplatesByCategory('database');

// Get popular templates
$popularTemplates = $task->getPopularTemplates(10);

// Get recent templates
$recentTemplates = $task->getRecentTemplates(10);

// Get recommendations
$recommendations = $task->getTemplateRecommendations();
```

### Template Import/Export

```php
// Export template
$jsonTemplate = $task->exportTemplate('my_template', 'json');
$yamlTemplate = $task->exportTemplate('my_template', 'yaml');
$xmlTemplate = $task->exportTemplate('my_template', 'xml');

// Import template
$task->importTemplate('/path/to/template.json');
```

### Template Versioning

```php
// Get version history
$versions = $task->getTemplateVersionHistory('my_template');

// Restore specific version
$task->restoreTemplateVersion('my_template', '1.2.0');

// Clone template
$task->cloneTemplate('source_template', 'new_template_name');
```

## Template Categories

### Built-in Categories

```php
$categories = [
    'database' => 'Database Operations',
    'file_operations' => 'File Operations',
    'system' => 'System Administration',
    'deployment' => 'Deployment',
    'backup' => 'Backup & Recovery',
    'monitoring' => 'Monitoring & Logging',
    'security' => 'Security',
    'maintenance' => 'System Maintenance',
    'testing' => 'Testing & QA',
    'development' => 'Development',
    'production' => 'Production',
    'custom' => 'Custom Templates',
];
```

### Custom Categories

```php
// Add custom categories
$task->addTemplateCategory('deployment', 'Deployment Tasks');
$task->addTemplateCategory('monitoring', 'Monitoring Tasks');
$task->addTemplateCategory('analytics', 'Analytics Tasks');
$task->addTemplateCategory('integration', 'Integration Tasks');
```

## Parameter Validation

### Parameter Types

```php
$parameterTypes = [
    'string' => 'Text string',
    'integer' => 'Whole number',
    'float' => 'Decimal number',
    'boolean' => 'True/false value',
    'array' => 'Array of values',
    'object' => 'Object/associative array',
    'enum' => 'One of predefined values',
];
```

### Validation Rules

```php
$validationRules = [
    'required' => 'Parameter is required',
    'type' => 'Data type validation',
    'min' => 'Minimum value (for numbers)',
    'max' => 'Maximum value (for numbers)',
    'pattern' => 'Regular expression pattern',
    'enum' => 'Allowed values array',
    'default' => 'Default value if not provided',
    'description' => 'Parameter description',
];
```

### Example Schema

```php
$schema = [
    'database_name' => [
        'type' => 'string',
        'required' => true,
        'description' => 'Database name to backup',
        'pattern' => '/^[a-zA-Z0-9_]+$/',
    ],
    'backup_retention' => [
        'type' => 'integer',
        'required' => false,
        'description' => 'Backup retention days',
        'default' => 30,
        'min' => 1,
        'max' => 365,
    ],
    'compression_level' => [
        'type' => 'integer',
        'required' => false,
        'description' => 'Compression level (1-9)',
        'default' => 6,
        'min' => 1,
        'max' => 9,
    ],
    'encrypt_backup' => [
        'type' => 'boolean',
        'required' => false,
        'description' => 'Encrypt backup files',
        'default' => false,
    ],
    'notification_email' => [
        'type' => 'string',
        'required' => false,
        'description' => 'Email for notifications',
        'pattern' => '/^[^\s@]+@[^\s@]+\.[^\s@]+$/',
    ],
    'environment' => [
        'type' => 'string',
        'required' => true,
        'description' => 'Environment type',
        'enum' => ['development', 'staging', 'production'],
    ],
];
```

## Configuration

### Task-Level Configuration

```php
$task->setTemplatesConfig([
    'enabled' => true,
    'categories' => [
        'custom' => 'Custom Templates',
        'database' => 'Database Operations',
        'production' => 'Production Tasks',
    ],
    'default_category' => 'custom',
    'auto_save' => true,
    'version_control' => true,
]);
```

### Global Configuration

Add to `config/task-runner.php`:

```php
return [
    'templates' => [
        'enabled' => true,
        'storage' => [
            'driver' => 'database', // database, file, cache
            'table' => 'task_templates',
            'cache_ttl' => 3600,
        ],
        'categories' => [
            'database' => 'Database Operations',
            'file_operations' => 'File Operations',
            'system' => 'System Administration',
            'deployment' => 'Deployment',
            'backup' => 'Backup & Recovery',
            'monitoring' => 'Monitoring & Logging',
            'security' => 'Security',
            'custom' => 'Custom Templates',
        ],
        'validation' => [
            'strict_mode' => true,
            'allow_unknown_parameters' => false,
            'auto_correct_types' => true,
        ],
        'import_export' => [
            'allowed_formats' => ['json', 'yaml', 'xml'],
            'max_file_size' => 1024 * 1024, // 1MB
            'allowed_extensions' => ['.json', '.yaml', '.yml', '.xml'],
        ],
        'versioning' => [
            'enabled' => true,
            'max_versions' => 10,
            'auto_version_on_update' => true,
        ],
        'recommendations' => [
            'enabled' => true,
            'algorithm' => 'usage_based', // usage_based, similarity_based
            'max_recommendations' => 5,
        ],
    ],
];
```

## Best Practices

### 1. Create Reusable Templates

```php
// Create production-ready template
$task->saveAsTemplate('production_ready', [
    'description' => 'Production-ready task with monitoring and rollback',
    'category' => 'production',
    'configuration' => [
        'monitoring' => [
            'enabled' => true,
            'check_interval' => 30,
        ],
        'analytics' => [
            'enabled' => true,
            'baseline' => [
                'execution_time' => 60,
                'memory_usage' => 50 * 1024 * 1024,
            ],
        ],
        'rollback' => [
            'enabled' => true,
            'timeout' => 300,
        ],
    ],
]);
```

### 2. Use Parameter Validation

```php
// Always validate parameters before using templates
$validation = $task->validateTemplateParameters('my_template', $parameters);
if (!$validation['valid']) {
    throw new \InvalidArgumentException('Invalid parameters: ' . implode(', ', $validation['errors']));
}
```

### 3. Organize Templates by Category

```php
// Use meaningful categories
$task->addTemplateCategory('production', 'Production Tasks');
$task->addTemplateCategory('development', 'Development Tasks');
$task->addTemplateCategory('testing', 'Testing Tasks');
```

### 4. Document Templates

```php
// Include comprehensive documentation
$task->saveAsTemplate('well_documented_template', [
    'description' => 'Comprehensive description of what this template does',
    'documentation' => 'https://docs.example.com/templates/well-documented-template',
    'examples' => [
        'basic_usage' => ['param1' => 'value1', 'param2' => 'value2'],
        'advanced_usage' => ['param1' => 'advanced_value', 'param2' => 'advanced_value2'],
    ],
    'dependencies' => [
        'required_software' => ['mysql', 'gzip'],
        'required_permissions' => ['database_access', 'file_write'],
    ],
]);
```

### 5. Version Your Templates

```php
// Use semantic versioning
$task->saveAsTemplate('versioned_template', [
    'version' => '1.2.3',
    'changelog' => [
        '1.2.3' => 'Added new parameter support',
        '1.2.2' => 'Fixed validation bug',
        '1.2.1' => 'Performance improvements',
    ],
]);
```

### 6. Use Template Recommendations

```php
// Leverage template recommendations
$recommendations = $task->getTemplateRecommendations();
foreach ($recommendations as $recommendation) {
    if ($recommendation['score'] > 0.8) {
        $task->createFromTemplate($recommendation['name'], $recommendation['suggested_parameters']);
    }
}
```

## Troubleshooting

### Common Issues

1. **Template Not Found**: Check template name and ensure it exists
2. **Parameter Validation Errors**: Review parameter schema and provided values
3. **Import/Export Failures**: Check file format and permissions
4. **Version Conflicts**: Use version management features

### Debug Commands

```bash
# List all templates
php artisan task:templates:list

# Search templates
php artisan task:templates:search "database"

# Export template
php artisan task:templates:export template-name --format=json

# Import template
php artisan task:templates:import /path/to/template.json

# Validate template
php artisan task:templates:validate template-name

# Get template usage stats
php artisan task:templates:stats template-name
```

This comprehensive templates and blueprints system provides immediate productivity boost through reusable task patterns, rapid task generation, and standardized configurations to accelerate development and deployment processes. 