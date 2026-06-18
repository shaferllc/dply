# Tasks Module Migration Plan

This plan documents the direct migration of the `@/Tasks` module into the unified `@/TaskRunner` module without legacy support.

## Migration Overview

The existing Tasks module will be merged into TaskRunner to create a unified task management system. All files will be moved directly with updated namespaces.

## File Migration Map

### Core Classes
- `app/Modules/Tasks/BaseTask.php` → `app/Modules/TaskRunner/BaseTask.php`
- `app/Modules/Tasks/TestTask.php` → `app/Modules/TaskRunner/TestTask.php`
- `app/Modules/Tasks/GenerateEd25519KeyPair.php` → `app/Modules/TaskRunner/GenerateEd25519KeyPair.php`
- `app/Modules/Tasks/GetFile.php` → `app/Modules/TaskRunner/GetFile.php`
- `app/Modules/Tasks/TrackTaskInBackground.php` → `app/Modules/TaskRunner/TrackTaskInBackground.php`

### Models
- `app/Modules/Tasks/Models/Task.php` → `app/Modules/TaskRunner/Models/Task.php`
- `app/Modules/Tasks/TaskFactory.php` → `app/Modules/TaskRunner/Models/TaskFactory.php`

### Enums
- `app/Modules/Tasks/Enums/TaskStatus.php` → `app/Modules/TaskRunner/Enums/TaskStatus.php`
- `app/Modules/Tasks/Enums/CallbackType.php` → `app/Modules/TaskRunner/Enums/CallbackType.php`

### Contracts
- `app/Modules/Tasks/Contracts/HasCallbacks.php` → `app/Modules/TaskRunner/Contracts/HasCallbacks.php`

### Traits
- `app/Modules/Tasks/Traits/HandlesCallbacks.php` → `app/Modules/TaskRunner/Traits/HandlesCallbacks.php`

### Jobs
- `app/Modules/Tasks/Jobs/UpdateTaskOutput.php` → `app/Modules/TaskRunner/Jobs/UpdateTaskOutput.php`

### Exceptions
- `app/Modules/Tasks/Exceptions/TaskFailedException.php` → `app/Modules/TaskRunner/Exceptions/TaskFailedException.php`

### Utilities
- `app/Modules/Tasks/Formatter.php` → `app/Modules/TaskRunner/Utilities/Formatter.php`

## Namespace Changes

### Old Namespaces
```php
namespace Dply\Tasks;
namespace Dply\Tasks\Models;
namespace Dply\Tasks\Enums;
namespace Dply\Tasks\Contracts;
namespace Dply\Tasks\Traits;
namespace Dply\Tasks\Jobs;
namespace Dply\Tasks\Exceptions;
```

### New Namespaces
```php
namespace App\Modules\TaskRunner;
namespace App\Modules\TaskRunner\Models;
namespace App\Modules\TaskRunner\Enums;
namespace App\Modules\TaskRunner\Contracts;
namespace App\Modules\TaskRunner\Traits;
namespace App\Modules\TaskRunner\Jobs;
namespace App\Modules\TaskRunner\Exceptions;
namespace App\Modules\TaskRunner\Utilities;
```

## Integration Points

### Enhanced BaseTask
The BaseTask will be enhanced to work with the new TaskRunner system:

```php
// New BaseTask will extend EnhancedTask and provide compatibility
abstract class BaseTask extends EnhancedTask
{
    // Enhanced functionality with TaskRunner features
    // Maintains existing API for smooth transition
}
```

### Unified Task Model
The Task model will be enhanced with TaskRunner capabilities:

```php
// Enhanced Task model with TaskRunner features
class Task extends Model
{
    // Existing functionality plus TaskRunner enhancements
    // Unified task management
}
```

### Enhanced CLI Commands
Existing CLI commands will be enhanced to support both old and new task types:

```bash
# Enhanced commands support both task types
php artisan task:list --include-legacy
php artisan task:run "task-name" --legacy-mode
```

## Migration Steps

### 1. Move Files
Move all files from Tasks module to TaskRunner with updated namespaces.

### 2. Update Namespaces
Update all namespace references in moved files.

### 3. Update Dependencies
Update any files that import from the old Tasks module.

### 4. Update Service Providers
Register the moved components in TaskRunner service provider.

### 5. Update Configuration
Update any configuration files that reference the old module.

### 6. Update Tests
Update test files to use new namespaces and locations.

## Benefits

### Unified Task Management
- Single module for all task operations
- Consistent API across all task types
- Enhanced monitoring and management

### Improved Features
- Real-time task monitoring
- Enhanced CLI commands
- Unified API endpoints
- Better error handling

### Simplified Architecture
- No legacy compatibility layer
- Cleaner codebase
- Easier maintenance
- Better performance

## Post-Migration

After migration, the old Tasks module can be completely removed, and all task management will be handled through the unified TaskRunner module. 