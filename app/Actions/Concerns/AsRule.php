<?php

namespace App\Actions\Concerns;

use Illuminate\Contracts\Validation\Rule;

/**
 * Allows actions to be used as Laravel validation rules.
 *
 * Implements the `Illuminate\Contracts\Validation\Rule` interface, allowing
 * actions to be used directly in validation rules arrays. Works with both
 * the trait's direct implementation and the RuleDecorator for additional
 * functionality.
 *
 * How it works:
 * - Implements `passes($attribute, $value)` method (calls `handle()`)
 * - Implements `message()` method (calls `getMessage()`)
 * - Can be used directly in validation rules arrays
 * - Automatically decorated by RuleDecorator when used in validation contexts
 * - Supports multiple method name patterns for flexibility
 *
 * Benefits:
 * - Use actions as validation rules
 * - Reusable validation logic
 * - Can access action dependencies (services, repositories, etc.)
 * - Custom error messages per rule
 * - Works with FormRequests, controllers, and manual validation
 *
 * Note: This trait implements the Rule interface directly, allowing actions
 * to be used as rules. The RuleDecorator provides additional wrapping when
 * actions are used in validation contexts, but the trait's implementation
 * works standalone as well.
 *
 * Does it need to be a decorator?
 * The current setup uses BOTH a trait and a decorator:
 * - Trait: Provides direct Rule interface implementation (passes() → handle(), message() → getMessage())
 * - Decorator: Provides enhanced wrapping with more method name flexibility
 * - Design Pattern: Automatically applies decorator when action is used in validation contexts
 *
 * This hybrid approach gives you:
 * - Direct use: Actions can be used as rules directly (trait implementation)
 * - Enhanced wrapping: Decorator provides fallbacks for different method names
 * - Automatic decoration: Design pattern applies decorator in validation contexts
 *
 * The trait alone is sufficient for basic use, but the decorator adds flexibility
 * and consistency with the decorator pattern used by other concerns.
 *
 * Method Patterns Supported:
 * - `handle($attribute, $value)` or `passes($attribute, $value)` - validation logic
 * - `getMessage()` or `message()` - error message
 *
 * @example
 * // Basic usage - unique email validation:
 * class UniqueEmailRule extends Actions implements Rule
 * {
 *     use AsRule;
 *
 *     public function handle($attribute, $value): bool
 *     {
 *         return ! User::where('email', $value)->exists();
 *     }
 *
 *     public function getMessage(): string
 *     {
 *         return 'This email is already taken.';
 *     }
 * }
 *
 * // Usage in FormRequest:
 * class RegisterRequest extends FormRequest
 * {
 *     public function rules(): array
 *     {
 *         return [
 *             'email' => ['required', 'email', new UniqueEmailRule],
 *         ];
 *     }
 * }
 * @example
 * // Using passes() method instead of handle():
 * class ValidUsernameRule extends Actions implements Rule
 * {
 *     use AsRule;
 *
 *     public function passes($attribute, $value): bool
 *     {
 *         // Username must be 3-20 characters, alphanumeric and underscores only
 *         if (strlen($value) < 3 || strlen($value) > 20) {
 *             return false;
 *         }
 *
 *         return preg_match('/^[a-zA-Z0-9_]+$/', $value) === 1;
 *     }
 *
 *     public function message(): string
 *     {
 *         return 'The username must be 3-20 characters and contain only letters, numbers, and underscores.';
 *     }
 * }
 *
 * // Usage:
 * $validator = Validator::make($data, [
 *     'username' => ['required', new ValidUsernameRule],
 * ]);
 * @example
 * // Complex validation with dependencies:
 * class TeamSlugAvailableRule extends Actions implements Rule
 * {
 *     use AsRule;
 *
 *     public function __construct(
 *         public ?Team $currentTeam = null
 *     ) {}
 *
 *     public function handle($attribute, $value): bool
 *     {
 *         $query = Team::where('slug', $value);
 *
 *         // Exclude current team if updating
 *         if ($this->currentTeam) {
 *             $query->where('id', '!=', $this->currentTeam->id);
 *         }
 *
 *         return ! $query->exists();
 *     }
 *
 *     public function getMessage(): string
 *     {
 *         return 'This team slug is already taken.';
 *     }
 * }
 *
 * // Usage in FormRequest:
 * class UpdateTeamRequest extends FormRequest
 * {
 *     public function rules(): array
 *     {
 *         return [
 *             'slug' => [
 *                 'required',
 *                 'string',
 *                 'max:255',
 *                 new TeamSlugAvailableRule($this->team),
 *             ],
 *         ];
 *     }
 * }
 * @example
 * // Dynamic error messages based on validation context:
 * class StrongPasswordRule extends Actions implements Rule
 * {
 *     use AsRule;
 *
 *     public function handle($attribute, $value): bool
 *     {
 *         // Must be at least 8 characters
 *         if (strlen($value) < 8) {
 *             $this->failureReason = 'too_short';
 *
 *             return false;
 *         }
 *
 *         // Must contain uppercase
 *         if (! preg_match('/[A-Z]/', $value)) {
 *             $this->failureReason = 'no_uppercase';
 *
 *             return false;
 *         }
 *
 *         // Must contain lowercase
 *         if (! preg_match('/[a-z]/', $value)) {
 *             $this->failureReason = 'no_lowercase';
 *
 *             return false;
 *         }
 *
 *         // Must contain number
 *         if (! preg_match('/[0-9]/', $value)) {
 *             $this->failureReason = 'no_number';
 *
 *             return false;
 *         }
 *
 *         return true;
 *     }
 *
 *     public string $failureReason = '';
 *
 *     public function getMessage(): string
 *     {
 *         return match ($this->failureReason) {
 *             'too_short' => 'The password must be at least 8 characters.',
 *             'no_uppercase' => 'The password must contain at least one uppercase letter.',
 *             'no_lowercase' => 'The password must contain at least one lowercase letter.',
 *             'no_number' => 'The password must contain at least one number.',
 *             default => 'The password does not meet the requirements.',
 *         };
 *     }
 * }
 *
 * // Usage:
 * $validator = Validator::make(['password' => 'weak'], [
 *     'password' => ['required', new StrongPasswordRule],
 * ]);
 * @example
 * // Validation with database relationships:
 * class UserBelongsToTeamRule extends Actions implements Rule
 * {
 *     use AsRule;
 *
 *     public function __construct(
 *         public Team $team
 *     ) {}
 *
 *     public function handle($attribute, $value): bool
 *     {
 *         return $this->team->users()->where('id', $value)->exists();
 *     }
 *
 *     public function getMessage(): string
 *     {
 *         return 'The selected user does not belong to this team.';
 *     }
 * }
 *
 * // Usage in controller:
 * class AssignUserController extends Controller
 * {
 *     public function store(Request $request, Team $team)
 *     {
 *         $request->validate([
 *             'user_id' => [
 *                 'required',
 *                 'exists:users,id',
 *                 new UserBelongsToTeamRule($team),
 *             ],
 *         ]);
 *
 *         // Assign user to team
 *     }
 * }
 * @example
 * // Conditional validation based on other fields:
 * class ConditionalEmailRule extends Actions implements Rule
 * {
 *     use AsRule;
 *
 *     public function __construct(
 *         public array $data
 *     ) {}
 *
 *     public function handle($attribute, $value): bool
 *     {
 *         // Only validate email if user_type is 'customer'
 *         if (($this->data['user_type'] ?? null) !== 'customer') {
 *             return true; // Skip validation
 *         }
 *
 *         // Validate email format and uniqueness
 *         if (! filter_var($value, FILTER_VALIDATE_EMAIL)) {
 *             return false;
 *         }
 *
 *         return ! User::where('email', $value)->exists();
 *     }
 *
 *     public function getMessage(): string
 *     {
 *         return 'The email must be valid and unique for customer accounts.';
 *     }
 * }
 *
 * // Usage:
 * $validator = Validator::make($data, [
 *     'user_type' => 'required|in:customer,admin',
 *     'email' => [
 *         'required_if:user_type,customer',
 *         new ConditionalEmailRule($data),
 *     ],
 * ]);
 * @example
 * // Custom validation with external API check:
 * class ValidDomainRule extends Actions implements Rule
 * {
 *     use AsRule;
 *
 *     public function handle($attribute, $value): bool
 *     {
 *         // Extract domain from email
 *         $domain = substr(strrchr($value, '@'), 1);
 *
 *         // Check if domain is valid (simplified example)
 *         return checkdnsrr($domain, 'MX');
 *     }
 *
 *     public function getMessage(): string
 *     {
 *         return 'The email domain is not valid or does not accept email.';
 *     }
 * }
 *
 * // Usage:
 * $validator = Validator::make(['email' => 'user@example.com'], [
 *     'email' => ['required', 'email', new ValidDomainRule],
 * ]);
 * @example
 * // Reusable validation rule with configuration:
 * class MinAgeRule extends Actions implements Rule
 * {
 *     use AsRule;
 *
 *     public function __construct(
 *         public int $minAge = 18
 *     ) {}
 *
 *     public function handle($attribute, $value): bool
 *     {
 *         if (! $value) {
 *             return false;
 *         }
 *
 *         $birthDate = \Carbon\Carbon::parse($value);
 *         $age = $birthDate->age;
 *
 *         return $age >= $this->minAge;
 *     }
 *
 *     public function getMessage(): string
 *     {
 *         return "You must be at least {$this->minAge} years old.";
 *     }
 * }
 *
 * // Usage:
 * $validator = Validator::make(['birth_date' => '2010-01-01'], [
 *     'birth_date' => ['required', 'date', new MinAgeRule(18)],
 * ]);
 *
 * // Different minimum age:
 * $validator = Validator::make(['birth_date' => '2010-01-01'], [
 *     'birth_date' => ['required', 'date', new MinAgeRule(21)], // 21+ only
 * ]);
 * @example
 * // Using with Livewire validation:
 * class UniqueTeamNameRule extends Actions implements Rule
 * {
 *     use AsRule;
 *
 *     public function __construct(
 *         public ?Team $currentTeam = null
 *     ) {}
 *
 *     public function handle($attribute, $value): bool
 *     {
 *         $query = Team::where('name', $value);
 *
 *         if ($this->currentTeam) {
 *             $query->where('id', '!=', $this->currentTeam->id);
 *         }
 *
 *         return ! $query->exists();
 *     }
 *
 *     public function getMessage(): string
 *     {
 *         return 'This team name is already taken.';
 *     }
 * }
 *
 * // Livewire Component:
 * class CreateTeam extends Component
 * {
 *     public string $name = '';
 *
 *     protected function rules(): array
 *     {
 *         return [
 *             'name' => [
 *                 'required',
 *                 'string',
 *                 'max:255',
 *                 new UniqueTeamNameRule,
 *             ],
 *         ];
 *     }
 *
 *     public function save(): void
 *     {
 *         $this->validate();
 *         // Create team
 *     }
 *
 *     public function render(): View
 *     {
 *         return view('livewire.create-team');
 *     }
 * }
 * @example
 * // Array validation (validating multiple values):
 * class AllItemsUniqueRule extends Actions implements Rule
 * {
 *     use AsRule;
 *
 *     public function handle($attribute, $value): bool
 *     {
 *         if (! is_array($value)) {
 *             return false;
 *         }
 *
 *         // Check if all items in array are unique
 *         return count($value) === count(array_unique($value));
 *     }
 *
 *     public function getMessage(): string
 *     {
 *         return 'All items in :attribute must be unique.';
 *     }
 * }
 *
 * // Usage:
 * $validator = Validator::make([
 *     'tags' => ['tag1', 'tag2', 'tag1'], // Duplicate!
 * ], [
 *     'tags' => ['required', 'array', new AllItemsUniqueRule],
 * ]);
 * // Validation fails: "All items in tags must be unique."
 */
trait AsRule
{
    public function passes($attribute, $value): bool
    {
        return $this->handle($attribute, $value);
    }

    public function message(): string
    {
        return $this->getValidationMessage() ?? 'The :attribute is invalid.';
    }

    protected function getValidationMessage(): ?string
    {
        return $this->hasMethod('getMessage')
            ? $this->callMethod('getMessage')
            : null;
    }
}
