<?php

namespace App\Actions\Concerns;

use App\Actions\Attributes\ValidationAttributes;
use App\Actions\Attributes\ValidationMessages;
use App\Actions\Attributes\ValidationRules;
use App\Actions\Decorators\ValidationDecorator;
use App\Actions\DesignPatterns\ValidationDesignPattern;
use Illuminate\Validation\ValidationException;

/**
 * Automatically validates input before action execution.
 *
 * Uses the decorator pattern to automatically wrap actions and validate input
 * before execution. The ValidationDecorator intercepts handle() calls and
 * validates arguments automatically.
 *
 * How it works:
 * 1. When an action uses AsValidated, ValidationDesignPattern recognizes it
 * 2. ActionManager wraps the action with ValidationDecorator
 * 3. When handle() is called, the decorator:
 *    - Validates the arguments using Laravel's Validator
 *    - Throws ValidationException if validation fails
 *    - Executes the action's handle() method with validated data
 *    - Returns the result
 *
 * @example
 * // ============================================
 * // Example 1: Minimal Setup (Using Attributes)
 * // ============================================
 * use App\Actions\Attributes\ValidationRules;
 *
 * #[ValidationRules([
 *     'email' => 'required|email|unique:users',
 *     'name' => 'required|string|min:3',
 * ])]
 * class CreateUser extends Actions
 * {
 *     use AsValidated;
 *
 *     public function handle(string $email, string $name): User
 *     {
 *         return User::create(['email' => $email, 'name' => $name]);
 *     }
 * }
 *
 * // Usage - validation happens automatically:
 * CreateUser::run('user@example.com', 'John Doe');
 * // Throws ValidationException if validation fails
 * @example
 * // ============================================
 * // Example 2: Full Configuration (Using Attributes)
 * // ============================================
 * use App\Actions\Attributes\ValidationAttributes;
 * use App\Actions\Attributes\ValidationMessages;
 * use App\Actions\Attributes\ValidationRules;
 *
 * #[ValidationRules([
 *     'email' => 'required|email|unique:users',
 *     'name' => 'required|string|min:3|max:255',
 *     'age' => 'nullable|integer|min:18|max:120',
 * ])]
 * #[ValidationMessages([
 *     'email.required' => 'Email is required',
 *     'email.email' => 'Email must be a valid email address',
 *     'email.unique' => 'This email is already registered',
 *     'name.min' => 'Name must be at least 3 characters',
 *     'age.min' => 'You must be at least 18 years old',
 * ])]
 * #[ValidationAttributes([
 *     'email' => 'email address',
 *     'name' => 'full name',
 *     'age' => 'age',
 * ])]
 * class CreateUser extends Actions
 * {
 *     use AsValidated;
 *
 *     public function handle(string $email, string $name, ?int $age = null): User
 *     {
 *         return User::create([
 *             'email' => $email,
 *             'name' => $name,
 *             'age' => $age,
 *         ]);
 *     }
 * }
 * @example
 * // ============================================
 * // Example 3: Using Methods Instead of Attributes
 * // ============================================
 * class CreateUser extends Actions
 * {
 *     use AsValidated;
 *
 *     public function handle(array $data): User
 *     {
 *         return User::create($data);
 *     }
 *
 *     // Define validation rules
 *     public function rules(): array
 *     {
 *         return [
 *             'email' => 'required|email|unique:users',
 *             'name' => 'required|string|min:3|max:255',
 *         ];
 *     }
 *
 *     // Custom validation messages
 *     public function messages(): array
 *     {
 *         return [
 *             'email.required' => 'Email is required',
 *             'email.email' => 'Email must be valid',
 *             'name.min' => 'Name must be at least 3 characters',
 *         ];
 *     }
 *
 *     // Custom attribute names for error messages
 *     public function attributes(): array
 *     {
 *         return [
 *             'email' => 'email address',
 *             'name' => 'full name',
 *         ];
 *     }
 * }
 *
 * // Usage:
 * CreateUser::run(['email' => 'user@example.com', 'name' => 'John Doe']);
 * @example
 * // ============================================
 * // Example 4: Custom Validator Logic
 * // ============================================
 * use App\Actions\Attributes\ValidationRules;
 * use Illuminate\Validation\Validator;
 *
 * #[ValidationRules([
 *     'email' => 'required|email',
 *     'password' => 'required|min:8',
 *     'password_confirmation' => 'required|same:password',
 * ])]
 * class CreateUser extends Actions
 * {
 *     use AsValidated;
 *
 *     public function handle(array $data): User
 *     {
 *         return User::create($data);
 *     }
 *
 *     // Custom validator logic
 *     public function withValidator(Validator $validator): void
 *     {
 *         $validator->after(function ($validator) {
 *             // Custom validation logic
 *             if ($this->isEmailBlocked($validator->getData()['email'] ?? '')) {
 *                 $validator->errors()->add('email', 'This email domain is blocked');
 *             }
 *
 *             // Cross-field validation
 *             $data = $validator->getData();
 *             if (isset($data['birth_date']) && $this->isUnderage($data['birth_date'])) {
 *                 $validator->errors()->add('birth_date', 'You must be at least 18 years old');
 *             }
 *         });
 *     }
 *
 *     protected function isEmailBlocked(string $email): bool
 *     {
 *         // Check if email domain is blocked
 *         return false;
 *     }
 *
 *     protected function isUnderage(string $birthDate): bool
 *     {
 *         // Check if user is underage
 *         return false;
 *     }
 * }
 * @example
 * // ============================================
 * // Example 5: Real-World Usage (from Tags\Actions\Create)
 * // ============================================
 * use App\Actions\Attributes\ValidationAttributes;
 * use App\Actions\Attributes\ValidationMessages;
 * use App\Actions\Attributes\ValidationRules;
 *
 * #[ValidationRules([
 *     'name' => 'required|string|min:1|max:255',
 *     'type' => 'nullable|string',
 * ])]
 * #[ValidationMessages([
 *     'name.required' => 'Tag name is required',
 *     'name.min' => 'Tag name must be at least 1 character',
 *     'name.max' => 'Tag name cannot exceed 255 characters',
 * ])]
 * #[ValidationAttributes([
 *     'name' => 'tag name',
 *     'type' => 'tag type',
 * ])]
 * class CreateTag extends Actions
 * {
 *     use AsValidated;
 *
 *     public function handle(Team $team, array $formData): Tag
 *     {
 *         // $formData is already validated
 *         $tag = Tag::create($formData);
 *         $team->tags()->attach($tag);
 *         return $tag;
 *     }
 * }
 *
 * // Usage:
 * try {
 *     $tag = CreateTag::run($team, ['name' => 'New Tag', 'type' => 'category']);
 * } catch (\Illuminate\Validation\ValidationException $e) {
 *     // Handle validation errors
 *     $errors = $e->errors();
 *     // $errors = ['name' => ['Tag name is required'], ...]
 * }
 * @example
 * // ============================================
 * // Example 6: Array Arguments Validation
 * // ============================================
 * // Validation works with array arguments passed to handle()
 * #[ValidationRules([
 *     'email' => 'required|email',
 *     'name' => 'required|string',
 * ])]
 * class CreateUser extends Actions
 * {
 *     use AsValidated;
 *
 *     // Array argument is automatically validated
 *     public function handle(array $data): User
 *     {
 *         // $data is already validated
 *         return User::create($data);
 *     }
 * }
 *
 * // Usage:
 * CreateUser::run(['email' => 'user@example.com', 'name' => 'John']);
 * @example
 * // ============================================
 * // Example 7: Handling Validation Errors
 * // ============================================
 * #[ValidationRules(['email' => 'required|email'])]
 * class CreateUser extends Actions
 * {
 *     use AsValidated;
 *
 *     public function handle(array $data): User
 *     {
 *         return User::create($data);
 *     }
 * }
 *
 * // In controller or Livewire component:
 * try {
 *     $user = CreateUser::run(['email' => 'invalid-email']);
 * } catch (\Illuminate\Validation\ValidationException $e) {
 *     // Get all errors
 *     $errors = $e->errors();
 *     // ['email' => ['The email must be a valid email address.']]
 *
 *     // Get first error for a field
 *     $firstError = $e->errors()['email'][0] ?? null;
 *
 *     // Return JSON response (for APIs)
 *     return response()->json(['errors' => $errors], 422);
 *
 *     // Or redirect back with errors (for web)
 *     return back()->withErrors($errors)->withInput();
 * }
 * @example
 * // ============================================
 * // Example 8: Dynamic Rules Based on Context
 * // ============================================
 * class CreateUser extends Actions
 * {
 *     use AsValidated;
 *
 *     public function handle(array $data): User
 *     {
 *         return User::create($data);
 *     }
 *
 *     // Dynamic rules based on context
 *     public function rules(): array
 *     {
 *         $rules = [
 *             'email' => 'required|email',
 *             'name' => 'required|string|min:3',
 *         ];
 *
 *         // Add conditional rules
 *         if (auth()->user()?->isAdmin()) {
 *             $rules['role'] = 'required|string|in:admin,user';
 *         }
 *
 *         // Version-specific rules
 *         if ($this->getVersion() === 'v2') {
 *             $rules['phone'] = 'required|string';
 *         }
 *
 *         return $rules;
 *     }
 * }
 * @example
 * // ============================================
 * // Default Behavior
 * // ============================================
 * // If no validation rules are defined (empty array or no rules() method),
 * // validation is skipped and the action executes normally.
 * //
 * // Validation happens BEFORE handle() is called.
 * // If validation fails, ValidationException is thrown.
 * // If validation passes, handle() is called with the (validated) arguments.
 * //
 * // Array arguments are automatically extracted and validated.
 * // Non-array arguments (objects, primitives) are passed through unchanged.
 * //
 * // Priority order for rules/messages/attributes:
 * // 1. PHP attributes (#[ValidationRules], etc.)
 * // 2. Methods (rules(), messages(), attributes())
 * // 3. Empty array (no validation)
 *
 * @see ValidationDecorator
 * @see ValidationDesignPattern
 * @see ValidationRules
 * @see ValidationMessages
 * @see ValidationAttributes
 * @see ValidationException
 */
trait AsValidated
{
    //
}
