<?php

declare(strict_types=1);

namespace App\Actions\Concerns;

/**
 * Converts actions into Laravel Artisan commands.
 *
 * This trait is a marker that enables automatic command registration via CommandDecorator.
 * When an action uses AsCommand, CommandDesignPattern recognizes it and
 * ActionManager wraps the action with CommandDecorator, which extends Laravel's Command class.
 *
 * How it works:
 * 1. Action uses AsCommand trait (marker)
 * 2. CommandDesignPattern recognizes the trait
 * 3. ActionManager wraps action with CommandDecorator
 * 4. CommandDecorator extends Laravel's Command class
 * 5. Command is automatically registered with Artisan
 * 6. When command runs, it calls action's asCommand() or handle() method
 *
 * Features:
 * - Automatic Artisan command registration
 * - Full Laravel Command API access
 * - Property or method-based configuration
 * - Command signature, name, description, help, and hidden status
 * - Works with ActionManager's decorator system
 * - Can be composed with other decorators
 * - No trait conflicts (marker trait only)
 *
 * Benefits:
 * - Reusable actions as commands
 * - Consistent command structure
 * - Full access to Command features (tables, progress bars, etc.)
 * - Composable with other decorators
 * - No trait method conflicts
 *
 * Use Cases:
 * - Data migration commands
 * - Maintenance tasks
 * - Scheduled jobs
 * - Administrative operations
 * - System health checks
 * - Data cleanup
 * - Report generation
 *
 * Note: This IS a decorator pattern. The trait is a marker that triggers
 * CommandDecorator, which automatically wraps actions and converts them to commands.
 * CommandDecorator must be the outermost decorator for commands.
 *
 * Configuration:
 * - Set `commandSignature` property or `getCommandSignature()` method (required)
 * - Set `commandName` property or `getCommandName()` method (optional)
 * - Set `commandDescription` property or `getCommandDescription()` method (optional)
 * - Set `commandHelp` property or `getCommandHelp()` method (optional)
 * - Set `commandHidden` property or `isCommandHidden()` method (optional, default: false)
 * - Implement `asCommand(Command $command)` method for command-specific logic
 * - Or use `handle()` method which receives the command instance
 *
 * @property-read  string $commandSignature
 *
 * @method string getCommandSignature()
 *
 * @property-read  string $commandName
 *
 * @method string getCommandName()
 *
 * @property-read  string $commandDescription
 *
 * @method string getCommandDescription()
 *
 * @property-read  string $commandHelp
 *
 * @method string getCommandHelp()
 *
 * @property-read  bool $commandHidden
 *
 * @method bool isCommandHidden()
 *
 * @example
 * // ============================================
 * // Example 1: Basic Command with Properties
 * // ============================================
 * class CleanupOldRecords extends Actions
 * {
 *     use AsCommand;
 *
 *     public string $commandSignature = 'app:cleanup {--days=30}';
 *     public string $commandDescription = 'Clean up old records from the database';
 *     public string $commandHelp = 'Remove records older than the specified number of days (default: 30)';
 *     public bool $commandHidden = false;
 *
 *     public function asCommand(Command $command): void
 *     {
 *         $days = (int) $command->option('days');
 *         $deleted = $this->handle($days);
 *
 *         $command->info("Deleted {$deleted} old records.");
 *     }
 *
 *     public function handle(int $days): int
 *     {
 *         return Model::where('created_at', '<', now()->subDays($days))->delete();
 *     }
 * }
 *
 * // Usage: php artisan app:cleanup --days=60
 * @example
 * // ============================================
 * // Example 2: Command with Methods Instead of Properties
 * // ============================================
 * class GenerateReport extends Actions
 * {
 *     use AsCommand;
 *
 *     protected function getCommandSignature(): string
 *     {
 *         return 'reports:generate {type} {--format=csv}';
 *     }
 *
 *     protected function getCommandDescription(): string
 *     {
 *         return 'Generate a report of the specified type';
 *     }
 *
 *     protected function getCommandHelp(): string
 *     {
 *         return 'Available types: sales, users, orders. Formats: csv, json, xlsx';
 *     }
 *
 *     protected function isCommandHidden(): bool
 *     {
 *         return false;
 *     }
 *
 *     public function asCommand(Command $command): void
 *     {
 *         $type = $command->argument('type');
 *         $format = $command->option('format');
 *
 *         $this->handle($type, $format);
 *
 *         $command->info("Report generated successfully!");
 *     }
 *
 *     public function handle(string $type, string $format): void
 *     {
 *         // Generate report...
 *     }
 * }
 *
 * // Usage: php artisan reports:generate sales --format=json
 * @example
 * // ============================================
 * // Example 3: Command with Arguments and Options
 * // ============================================
 * class SendEmail extends Actions
 * {
 *     use AsCommand;
 *
 *     public string $commandSignature = 'email:send {email} {subject} {--queue} {--template=default}';
 *     public string $commandDescription = 'Send an email to a user';
 *
 *     public function asCommand(Command $command): void
 *     {
 *         $email = $command->argument('email');
 *         $subject = $command->argument('subject');
 *         $useQueue = $command->option('queue');
 *         $template = $command->option('template');
 *
 *         if ($useQueue) {
 *             $this->handle($email, $subject, $template);
 *             $command->info("Email queued for {$email}");
 *         } else {
 *             $this->handle($email, $subject, $template);
 *             $command->info("Email sent to {$email}");
 *         }
 *     }
 *
 *     public function handle(string $email, string $subject, string $template): void
 *     {
 *         Mail::to($email)->send(new CustomEmail($subject, $template));
 *     }
 * }
 *
 * // Usage: php artisan email:send user@example.com "Hello" --queue --template=welcome
 * @example
 * // ============================================
 * // Example 4: Command with Table Output
 * // ============================================
 * class ListUsers extends Actions
 * {
 *     use AsCommand;
 *
 *     public string $commandSignature = 'users:list {--role=}';
 *     public string $commandDescription = 'List all users';
 *
 *     public function asCommand(Command $command): void
 *     {
 *         $role = $command->option('role');
 *         $users = $this->handle($role);
 *
 *         $command->table(
 *             ['ID', 'Name', 'Email', 'Role'],
 *             $users->map(fn ($user) => [
 *                 $user->id,
 *                 $user->name,
 *                 $user->email,
 *                 $user->role,
 *             ])->toArray()
 *         );
 *     }
 *
 *     public function handle(?string $role = null)
 *     {
 *         $query = User::query();
 *
 *         if ($role) {
 *             $query->where('role', $role);
 *         }
 *
 *         return $query->get();
 *     }
 * }
 *
 * // Usage: php artisan users:list --role=admin
 * @example
 * // ============================================
 * // Example 5: Command with Progress Bar
 * // ============================================
 * class ProcessItems extends Actions
 * {
 *     use AsCommand;
 *
 *     public string $commandSignature = 'items:process';
 *     public string $commandDescription = 'Process all pending items';
 *
 *     public function asCommand(Command $command): void
 *     {
 *         $items = Item::where('status', 'pending')->get();
 *
 *         $bar = $command->output->createProgressBar($items->count());
 *         $bar->start();
 *
 *         foreach ($items as $item) {
 *             $this->handle($item);
 *             $bar->advance();
 *         }
 *
 *         $bar->finish();
 *         $command->newLine();
 *         $command->info("Processed {$items->count()} items.");
 *     }
 *
 *     public function handle(Item $item): void
 *     {
 *         // Process item...
 *         $item->update(['status' => 'processed']);
 *     }
 * }
 *
 * // Usage: php artisan items:process
 * @example
 * // ============================================
 * // Example 6: Command with Confirmation
 * // ============================================
 * class DeleteOldData extends Actions
 * {
 *     use AsCommand;
 *
 *     public string $commandSignature = 'data:cleanup {--force}';
 *     public string $commandDescription = 'Delete old data from the database';
 *
 *     public function asCommand(Command $command): void
 *     {
 *         if (! $command->option('force') && ! $command->confirm('Are you sure you want to delete old data?')) {
 *             $command->info('Operation cancelled.');
 *             return;
 *         }
 *
 *         $deleted = $this->handle();
 *         $command->info("Deleted {$deleted} records.");
 *     }
 *
 *     public function handle(): int
 *     {
 *         return OldData::where('created_at', '<', now()->subYear())->delete();
 *     }
 * }
 *
 * // Usage: php artisan data:cleanup
 * // or: php artisan data:cleanup --force
 * @example
 * // ============================================
 * // Example 7: Command with Choice Prompt
 * // ============================================
 * class ManageCache extends Actions
 * {
 *     use AsCommand;
 *
 *     public string $commandSignature = 'cache:manage';
 *     public string $commandDescription = 'Manage application cache';
 *
 *     public function asCommand(Command $command): void
 *     {
 *         $action = $command->choice(
 *             'What would you like to do?',
 *             ['clear', 'warm', 'show'],
 *             0
 *         );
 *
 *         match($action) {
 *             'clear' => $this->handle('clear'),
 *             'warm' => $this->handle('warm'),
 *             'show' => $this->handle('show'),
 *         };
 *
 *         $command->info("Cache {$action} completed.");
 *     }
 *
 *     public function handle(string $action): void
 *     {
 *         match($action) {
 *             'clear' => Cache::flush(),
 *             'warm' => $this->warmCache(),
 *             'show' => $this->showCacheStats(),
 *         };
 *     }
 * }
 *
 * // Usage: php artisan cache:manage
 * @example
 * // ============================================
 * // Example 8: Hidden Command
 * // ============================================
 * class InternalMaintenance extends Actions
 * {
 *     use AsCommand;
 *
 *     public string $commandSignature = 'internal:maintenance';
 *     public string $commandDescription = 'Internal maintenance task';
 *     public bool $commandHidden = true; // Hidden from command list
 *
 *     public function asCommand(Command $command): void
 *     {
 *         $this->handle();
 *         $command->info('Maintenance completed.');
 *     }
 *
 *     public function handle(): void
 *     {
 *         // Internal maintenance logic...
 *     }
 * }
 *
 * // Usage: php artisan internal:maintenance
 * // (Command won't appear in php artisan list)
 * @example
 * // ============================================
 * // Example 9: Command with Scheduled Execution
 * // ============================================
 * class DailyBackup extends Actions
 * {
 *     use AsCommand;
 *
 *     public string $commandSignature = 'backup:daily';
 *     public string $commandDescription = 'Create daily backup';
 *
 *     public function asCommand(Command $command): void
 *     {
 *         $command->info('Starting backup...');
 *
 *         $backupPath = $this->handle();
 *
 *         $command->info("Backup created: {$backupPath}");
 *     }
 *
 *     public function handle(): string
 *     {
 *         // Create backup...
 *         return storage_path('backups/'.now()->format('Y-m-d').'.zip');
 *     }
 * }
 *
 * // In routes/console.php or AppServiceProvider:
 * // Schedule::command(DailyBackup::class)->daily();
 * @example
 * // ============================================
 * // Example 10: Command Using handle() Instead of asCommand()
 * // ============================================
 * class UpdateStatus extends Actions
 * {
 *     use AsCommand;
 *
 *     public string $commandSignature = 'status:update {id} {status}';
 *     public string $commandDescription = 'Update status of an item';
 *
 *     // If asCommand() is not defined, handle() is called with command instance
 *     public function handle(Command $command): void
 *     {
 *         $id = $command->argument('id');
 *         $status = $command->argument('status');
 *
 *         $item = Item::findOrFail($id);
 *         $item->update(['status' => $status]);
 *
 *         $command->info("Item {$id} status updated to {$status}");
 *     }
 * }
 *
 * // Usage: php artisan status:update 123 active
 * @example
 * // ============================================
 * // Example 11: Command with Multiple Arguments
 * // ============================================
 * class CreateUser extends Actions
 * {
 *     use AsCommand;
 *
 *     public string $commandSignature = 'user:create {name} {email} {--role=user} {--verified}';
 *     public string $commandDescription = 'Create a new user';
 *
 *     public function asCommand(Command $command): void
 *     {
 *         $name = $command->argument('name');
 *         $email = $command->argument('email');
 *         $role = $command->option('role');
 *         $verified = $command->option('verified');
 *
 *         $user = $this->handle($name, $email, $role, $verified);
 *
 *         $command->info("User created: {$user->email}");
 *     }
 *
 *     public function handle(string $name, string $email, string $role, bool $verified): User
 *     {
 *         return User::create([
 *             'name' => $name,
 *             'email' => $email,
 *             'role' => $role,
 *             'email_verified_at' => $verified ? now() : null,
 *         ]);
 *     }
 * }
 *
 * // Usage: php artisan user:create "John Doe" john@example.com --role=admin --verified
 * @example
 * // ============================================
 * // Example 12: Command with Error Handling
 * // ============================================
 * class ImportData extends Actions
 * {
 *     use AsCommand;
 *
 *     public string $commandSignature = 'data:import {file}';
 *     public string $commandDescription = 'Import data from a file';
 *
 *     public function asCommand(Command $command): void
 *     {
 *         $file = $command->argument('file');
 *
 *         if (! file_exists($file)) {
 *             $command->error("File not found: {$file}");
 *             return 1;
 *         }
 *
 *         try {
 *             $imported = $this->handle($file);
 *             $command->info("Imported {$imported} records.");
 *             return 0;
 *         } catch (\Exception $e) {
 *             $command->error("Import failed: {$e->getMessage()}");
 *             return 1;
 *         }
 *     }
 *
 *     public function handle(string $file): int
 *     {
 *         // Import logic...
 *         return 100;
 *     }
 * }
 *
 * // Usage: php artisan data:import /path/to/file.csv
 */
trait AsCommand
{
    //
}
