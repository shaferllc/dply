<?php

declare(strict_types=1);

namespace App\Actions\Concerns;

/**
 * Automatically logs audit trails for actions.
 *
 * This trait is a marker that enables automatic audit logging via AuditableDecorator.
 * When an action uses AsAuditable, AuditableDesignPattern recognizes it and
 * ActionManager wraps the action with AuditableDecorator.
 *
 * How it works:
 * 1. Action uses AsAuditable trait (marker)
 * 2. AuditableDesignPattern recognizes the trait
 * 3. ActionManager wraps action with AuditableDecorator
 * 4. When handle() is called, the decorator:
 *    - Captures before state (if applicable)
 *    - Executes the action
 *    - Captures after state (if applicable)
 *    - Records comprehensive audit log
 *
 * Features:
 * - Automatic audit trail logging
 * - Before/after state capture
 * - Sensitive data sanitization
 * - User, IP, and user agent tracking
 * - Exception logging
 * - Custom audit data support
 * - Custom storage support
 * - Works with ActionManager's decorator system
 * - Can be composed with other decorators
 * - No trait conflicts (marker trait only)
 *
 * Benefits:
 * - Complete audit trail for compliance
 * - Automatic change tracking
 * - User activity monitoring
 * - Debugging and troubleshooting
 * - Regulatory compliance (GDPR, SOX, etc.)
 * - No trait method conflicts
 * - Composable with other decorators
 *
 * Use Cases:
 * - Data modifications
 * - User actions
 * - Administrative changes
 * - Financial transactions
 * - Security-sensitive operations
 * - Compliance requirements
 * - Change tracking
 *
 * Note: This IS a decorator pattern. The trait is a marker that triggers
 * AuditableDecorator, which automatically wraps actions and adds audit logging.
 * This follows the same pattern as AsDebounced, AsAuthorized, AsLock, and other
 * decorator-based concerns.
 *
 * Configuration:
 * - Set `getBeforeState(array $arguments)` method to customize before state capture
 * - Set `getAfterState($result, array $arguments)` method to customize after state capture
 * - Set `getAuditData($result, array $arguments)` method to add custom audit data
 * - Implement `storeAuditRecord(array $auditData)` for custom storage
 *
 * @example
 * // ============================================
 * // Example 1: Basic Audit Logging
 * // ============================================
 * class DeleteRecord extends Actions
 * {
 *     use AsAuditable;
 *
 *     public function handle(Record $record): void
 *     {
 *         $record->delete();
 *     }
 * }
 *
 * // Usage
 * DeleteRecord::run($record);
 * // Automatically logs: user, action, arguments, before/after state, IP, timestamp
 * @example
 * // ============================================
 * // Example 2: Custom Audit Data
 * // ============================================
 * class UpdateUser extends Actions
 * {
 *     use AsAuditable;
 *
 *     public function handle(User $user, array $data): User
 *     {
 *         $user->update($data);
 *
 *         return $user->fresh();
 *     }
 *
 *     protected function getAuditData($result, array $arguments): array
 *     {
 *         [$user, $data] = $arguments;
 *
 *         return [
 *             'user_id' => $user->id,
 *             'changed_fields' => array_keys($data),
 *             'email_changed' => isset($data['email']),
 *         ];
 *     }
 * }
 *
 * // Usage
 * UpdateUser::run($user, ['name' => 'John', 'email' => 'john@example.com']);
 * // Logs custom audit data including changed fields
 * @example
 * // ============================================
 * // Example 3: Custom Before/After State
 * // ============================================
 * class TransferFunds extends Actions
 * {
 *     use AsAuditable;
 *
 *     public function handle(Account $from, Account $to, float $amount): void
 *     {
 *         $from->withdraw($amount);
 *         $to->deposit($amount);
 *     }
 *
 *     protected function getBeforeState(array $arguments): array
 *     {
 *         [$from, $to] = $arguments;
 *
 *         return [
 *             'from_balance' => $from->balance,
 *             'to_balance' => $to->balance,
 *         ];
 *     }
 *
 *     protected function getAfterState($result, array $arguments): array
 *     {
 *         [$from, $to] = $arguments;
 *
 *         return [
 *             'from_balance' => $from->fresh()->balance,
 *             'to_balance' => $to->fresh()->balance,
 *         ];
 *     }
 * }
 *
 * // Usage
 * TransferFunds::run($fromAccount, $toAccount, 1000.00);
 * // Logs before/after balances for both accounts
 * @example
 * // ============================================
 * // Example 4: Custom Storage
 * // ============================================
 * class ProcessPayment extends Actions
 * {
 *     use AsAuditable;
 *
 *     public function handle(Order $order, PaymentMethod $method): Payment
 *     {
 *         return Payment::create([
 *             'order_id' => $order->id,
 *             'method_id' => $method->id,
 *             'amount' => $order->total,
 *         ]);
 *     }
 *
 *     protected function storeAuditRecord(array $auditData): void
 *     {
 *         // Store in custom audit service
 *         AuditService::log($auditData);
 *
 *         // Also store in database
 *         DB::table('payment_audits')->insert($auditData);
 *     }
 * }
 *
 * // Usage
 * ProcessPayment::run($order, $method);
 * // Stores audit in both custom service and database
 * @example
 * // ============================================
 * // Example 5: Financial Transaction Auditing
 * // ============================================
 * class CreateInvoice extends Actions
 * {
 *     use AsAuditable;
 *
 *     public function handle(Order $order): Invoice
 *     {
 *         return Invoice::create([
 *             'order_id' => $order->id,
 *             'amount' => $order->total,
 *             'status' => 'pending',
 *         ]);
 *     }
 *
 *     protected function getAuditData($result, array $arguments): array
 *     {
 *         [$order] = $arguments;
 *
 *         return [
 *             'invoice_id' => $result->id,
 *             'order_id' => $order->id,
 *             'amount' => $result->amount,
 *             'currency' => $order->currency,
 *             'compliance_required' => true,
 *         ];
 *     }
 * }
 *
 * // Usage
 * CreateInvoice::run($order);
 * // Logs financial transaction with compliance flag
 * @example
 * // ============================================
 * // Example 6: User Permission Changes
 * // ============================================
 * class UpdateUserRole extends Actions
 * {
 *     use AsAuditable;
 *
 *     public function handle(User $user, string $role): User
 *     {
 *         $user->update(['role' => $role]);
 *
 *         return $user->fresh();
 *     }
 *
 *     protected function getBeforeState(array $arguments): array
 *     {
 *         [$user] = $arguments;
 *
 *         return [
 *             'old_role' => $user->role,
 *             'permissions' => $user->permissions->pluck('name')->toArray(),
 *         ];
 *     }
 *
 *     protected function getAfterState($result, array $arguments): array
 *     {
 *         [$user, $role] = $arguments;
 *
 *         return [
 *             'new_role' => $role,
 *             'permissions' => $result->permissions->pluck('name')->toArray(),
 *         ];
 *     }
 *
 *     protected function getAuditData($result, array $arguments): array
 *     {
 *         return [
 *             'security_event' => true,
 *             'permission_change' => true,
 *         ];
 *     }
 * }
 *
 * // Usage
 * UpdateUserRole::run($user, 'admin');
 * // Logs role change with before/after permissions
 * @example
 * // ============================================
 * // Example 7: Data Export Auditing
 * // ============================================
 * class ExportData extends Actions
 * {
 *     use AsAuditable;
 *
 *     public function handle(string $format, array $filters): string
 *     {
 *         $data = Data::where($filters)->get();
 *         $filePath = Storage::put("exports/{$format}/".now()->timestamp.'.'.$format, $data);
 *
 *         return $filePath;
 *     }
 *
 *     protected function getAuditData($result, array $arguments): array
 *     {
 *         [$format, $filters] = $arguments;
 *
 *         return [
 *             'export_format' => $format,
 *             'filters_applied' => $filters,
 *             'file_path' => $result,
 *             'data_sensitivity' => 'high',
 *         ];
 *     }
 * }
 *
 * // Usage
 * ExportData::run('csv', ['department' => 'finance']);
 * // Logs data export with sensitivity flag
 * @example
 * // ============================================
 * // Example 8: Configuration Changes
 * // ============================================
 * class UpdateSystemConfig extends Actions
 * {
 *     use AsAuditable;
 *
 *     public function handle(array $config): void
 *     {
 *         foreach ($config as $key => $value) {
 *             Setting::updateOrCreate(['key' => $key], ['value' => $value]);
 *         }
 *     }
 *
 *     protected function getBeforeState(array $arguments): array
 *     {
 *         [$config] = $arguments;
 *         $before = [];
 *
 *         foreach (array_keys($config) as $key) {
 *             $before[$key] = Setting::where('key', $key)->value('value');
 *         }
 *
 *         return $before;
 *     }
 *
 *     protected function getAfterState($result, array $arguments): array
 *     {
 *         [$config] = $arguments;
 *
 *         return $config;
 *     }
 * }
 *
 * // Usage
 * UpdateSystemConfig::run(['maintenance_mode' => true, 'max_users' => 1000]);
 * // Logs system configuration changes
 * @example
 * // ============================================
 * // Example 9: API Key Management
 * // ============================================
 * class RevokeApiKey extends Actions
 * {
 *     use AsAuditable;
 *
 *     public function handle(ApiKey $key): void
 *     {
 *         $key->update(['revoked_at' => now()]);
 *     }
 *
 *     protected function getBeforeState(array $arguments): array
 *     {
 *         [$key] = $arguments;
 *
 *         return [
 *             'key_id' => $key->id,
 *             'status' => $key->status,
 *             'last_used' => $key->last_used_at,
 *         ];
 *     }
 *
 *     protected function getAuditData($result, array $arguments): array
 *     {
 *         return [
 *             'security_event' => true,
 *             'action_type' => 'revocation',
 *         ];
 *     }
 * }
 *
 * // Usage
 * RevokeApiKey::run($apiKey);
 * // Logs API key revocation as security event
 * @example
 * // ============================================
 * // Example 10: Bulk Operations Auditing
 * // ============================================
 * class BulkDeleteRecords extends Actions
 * {
 *     use AsAuditable;
 *
 *     public function handle(array $recordIds): int
 *     {
 *         return Record::whereIn('id', $recordIds)->delete();
 *     }
 *
 *     protected function getBeforeState(array $arguments): array
 *     {
 *         [$recordIds] = $arguments;
 *
 *         return [
 *             'count' => count($recordIds),
 *             'record_ids' => $recordIds,
 *         ];
 *     }
 *
 *     protected function getAfterState($result, array $arguments): array
 *     {
 *         return [
 *             'deleted_count' => $result,
 *         ];
 *     }
 * }
 *
 * // Usage
 * BulkDeleteRecords::run([1, 2, 3, 4, 5]);
 * // Logs bulk deletion with count and IDs
 * @example
 * // ============================================
 * // Example 11: Exception Logging
 * // ============================================
 * class ProcessOrder extends Actions
 * {
 *     use AsAuditable;
 *
 *     public function handle(Order $order): void
 *     {
 *         // This might throw an exception
 *         $order->process();
 *     }
 * }
 *
 * // Usage
 * try {
 *     ProcessOrder::run($order);
 * } catch (\Exception $e) {
 *     // Exception is automatically logged in audit trail
 * }
 * // Audit log includes exception message even if action fails
 * @example
 * // ============================================
 * // Example 12: Sensitive Data Sanitization
 * // ============================================
 * class UpdateUserPassword extends Actions
 * {
 *     use AsAuditable;
 *
 *     public function handle(User $user, string $password): void
 *     {
 *         $user->update(['password' => Hash::make($password)]);
 *     }
 * }
 *
 * // Usage
 * UpdateUserPassword::run($user, 'newpassword123');
 * // Password is automatically sanitized in audit log (shows as ***REDACTED***)
 */
trait AsAuditable
{
    // This is a marker trait - the actual audit logging functionality is handled by AuditableDecorator
    // via the AuditableDesignPattern and ActionManager
}
