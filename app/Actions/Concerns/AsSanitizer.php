<?php

namespace App\Actions\Concerns;

use Illuminate\Support\Str;

/**
 * Provides data sanitization utilities for actions.
 *
 * Adds sanitization capabilities to actions, allowing them to clean and
 * normalize input data. Sanitization removes HTML tags, trims whitespace,
 * normalizes case, and applies custom rules per field.
 *
 * How it works:
 * - Provides `sanitize()` method for data cleaning
 * - Supports arrays and strings
 * - Applies default sanitization (trim, strip_tags, htmlspecialchars)
 * - Allows custom sanitization rules per field
 * - Recursively sanitizes nested arrays
 * - Sanitizes array keys as well as values
 *
 * Benefits:
 * - Clean user input automatically
 * - Remove HTML/XSS risks
 * - Normalize data formats
 * - Custom rules per field
 * - Recursive array sanitization
 * - Explicit control over sanitization
 *
 * Note: This is NOT a decorator - it provides utility methods that
 * actions can call explicitly. Sanitization is opt-in and explicit,
 * giving you full control over when and how to sanitize data.
 *
 * Does it need to be a decorator?
 * No. The current trait-based approach works well because:
 * - Sanitization is explicit and opt-in
 * - You control when to sanitize (before/after validation, etc.)
 * - Different fields may need different sanitization
 * - Some data shouldn't be sanitized (e.g., HTML content in rich text)
 * - The trait pattern is simpler for utility methods
 *
 * A decorator would automatically sanitize all input, which might be
 * too aggressive. The current approach lets you sanitize selectively.
 *
 * Available Sanitization Rules:
 * - `trim`: Remove leading/trailing whitespace
 * - `lowercase`: Convert to lowercase
 * - `uppercase`: Convert to uppercase
 * - `title`: Convert to title case
 * - `strip_tags`: Remove HTML tags
 * - `htmlspecialchars`: Convert special characters to HTML entities
 * - `htmlentities`: Convert all applicable characters to HTML entities
 *
 * @example
 * // Basic usage - sanitize user input:
 * class ProcessUserInput extends Actions
 * {
 *     use AsSanitizer;
 *
 *     public function handle(array $input): array
 *     {
 *         // Sanitize input before processing
 *         $clean = $this->sanitize($input);
 *
 *         // Process sanitized data
 *         return ['processed' => true, 'data' => $clean];
 *     }
 * }
 *
 * // Usage:
 * $action = ProcessUserInput::make();
 * $result = $action->handle([
 *     'name' => '  John Doe  ',
 *     'email' => 'JOHN@EXAMPLE.COM',
 *     'message' => '<script>alert("xss")</script>Hello',
 * ]);
 * // $result['data'] = [
 * //     'name' => 'John Doe',  // Trimmed
 * //     'email' => 'JOHN@EXAMPLE.COM',  // Default sanitization
 * //     'message' => 'Hello',  // HTML tags removed
 * // ]
 * @example
 * // Custom sanitization rules per field:
 * class CreateUser extends Actions
 * {
 *     use AsSanitizer;
 *
 *     public function handle(array $data): User
 *     {
 *         // Sanitize with custom rules
 *         $clean = $this->sanitize($data);
 *
 *         return User::create($clean);
 *     }
 *
 *     public function getSanitizationRules(): array
 *     {
 *         return [
 *             'email' => 'trim|lowercase',        // Normalize email
 *             'name' => 'trim|title',             // Title case name
 *             'username' => 'trim|lowercase',    // Lowercase username
 *             'bio' => 'strip_tags|trim',        // Remove HTML from bio
 *             'phone' => 'trim',                 // Just trim phone
 *         ];
 *     }
 * }
 *
 * // Usage:
 * $action = CreateUser::make();
 * $user = $action->handle([
 *     'email' => '  JOHN@EXAMPLE.COM  ',
 *     'name' => 'john doe',
 *     'username' => 'JohnDoe',
 *     'bio' => '<p>My bio</p>',
 *     'phone' => ' 123-456-7890 ',
 * ]);
 * // $user->email = 'john@example.com'
 * // $user->name = 'John Doe'
 * // $user->username = 'johndoe'
 * // $user->bio = 'My bio'
 * // $user->phone = '123-456-7890'
 * @example
 * // Sanitizing form submissions:
 * class ProcessContactForm extends Actions
 * {
 *     use AsSanitizer;
 *
 *     public function handle(array $formData): array
 *     {
 *         // Sanitize form data
 *         $clean = $this->sanitize($formData);
 *
 *         // Validate sanitized data
 *         $validated = $this->validate($clean);
 *
 *         // Process form
 *         return $this->processForm($validated);
 *     }
 *
 *     public function getSanitizationRules(): array
 *     {
 *         return [
 *             'name' => 'trim|title',
 *             'email' => 'trim|lowercase',
 *             'subject' => 'trim|strip_tags',
 *             'message' => 'strip_tags|trim',  // Remove HTML but keep text
 *         ];
 *     }
 *
 *     protected function validate(array $data): array
 *     {
 *         // Validation logic
 *         return $data;
 *     }
 *
 *     protected function processForm(array $data): array
 *     {
 *         // Process form
 *         return ['success' => true];
 *     }
 * }
 *
 * // Usage:
 * $action = ProcessContactForm::make();
 * $result = $action->handle([
 *     'name' => '  jane smith  ',
 *     'email' => 'JANE@EXAMPLE.COM',
 *     'subject' => '<b>Hello</b>',
 *     'message' => '<p>My message</p>',
 * ]);
 * @example
 * // Sanitizing nested arrays:
 * class ProcessBulkData extends Actions
 * {
 *     use AsSanitizer;
 *
 *     public function handle(array $data): array
 *     {
 *         // Recursively sanitize nested arrays
 *         // Nested arrays are automatically sanitized with default rules
 *         return $this->sanitize($data);
 *     }
 *
 *     public function getSanitizationRules(): array
 *     {
 *         return [
 *             // Rules apply to top-level keys
 *             'batch_id' => 'trim',
 *             'source' => 'trim|lowercase',
 *         ];
 *     }
 * }
 *
 * // Usage:
 * $action = ProcessBulkData::make();
 * $result = $action->handle([
 *     'batch_id' => '  123  ',
 *     'source' => 'IMPORT',
 *     'users' => [
 *         ['name' => '  john  ', 'email' => 'JOHN@EXAMPLE.COM'],
 *         ['name' => '  jane  ', 'email' => 'JANE@EXAMPLE.COM'],
 *     ],
 * ]);
 * // Top-level keys are sanitized with custom rules
 * // Nested arrays are sanitized with default rules (trim, strip_tags, htmlspecialchars)
 * @example
 * // Sanitizing strings directly:
 * class CleanSearchQuery extends Actions
 * {
 *     use AsSanitizer;
 *
 *     public function handle(string $query): string
 *     {
 *         // Sanitize search query
 *         return $this->sanitize($query);
 *     }
 * }
 *
 * // Usage:
 * $action = CleanSearchQuery::make();
 * $clean = $action->handle('  <script>alert("xss")</script>search term  ');
 * // $clean = 'search term'  // HTML removed, trimmed
 * @example
 * // Selective sanitization (some fields not sanitized):
 * class SaveArticle extends Actions
 * {
 *     use AsSanitizer;
 *
 *     public function handle(array $data): Article
 *     {
 *         // Only sanitize specific fields
 *         $sanitized = $this->sanitize($data);
 *
 *         // But preserve HTML in content field
 *         $sanitized['content'] = $data['content']; // Original HTML content
 *
 *         return Article::create($sanitized);
 *     }
 *
 *     public function getSanitizationRules(): array
 *     {
 *         return [
 *             'title' => 'trim|strip_tags',      // Sanitize title
 *             'slug' => 'trim|lowercase',       // Sanitize slug
 *             'excerpt' => 'strip_tags|trim',   // Sanitize excerpt
 *             // 'content' is NOT in rules, so it won't be sanitized
 *         ];
 *     }
 * }
 *
 * // Usage:
 * $action = SaveArticle::make();
 * $article = $action->handle([
 *     'title' => '<h1>My Title</h1>',
 *     'slug' => 'MY-SLUG',
 *     'excerpt' => '<p>Excerpt</p>',
 *     'content' => '<p>Full HTML content</p>',  // Preserved as-is
 * ]);
 * // $article->title = 'My Title'  // Sanitized
 * // $article->content = '<p>Full HTML content</p>'  // Not sanitized
 * @example
 * // Using with validation (sanitize before validate):
 * class CreateProduct extends Actions
 * {
 *     use AsSanitizer;
 *
 *     public function handle(array $data): Product
 *     {
 *         // Step 1: Sanitize input
 *         $sanitized = $this->sanitize($data);
 *
 *         // Step 2: Validate sanitized data
 *         $validated = validator($sanitized, [
 *             'name' => 'required|string|max:255',
 *             'price' => 'required|numeric|min:0',
 *             'description' => 'required|string',
 *         ])->validate();
 *
 *         // Step 3: Create product with clean, validated data
 *         return Product::create($validated);
 *     }
 *
 *     public function getSanitizationRules(): array
 *     {
 *         return [
 *             'name' => 'trim|title',
 *             'description' => 'strip_tags|trim',
 *         ];
 *     }
 * }
 *
 * // Usage:
 * $action = CreateProduct::make();
 * $product = $action->handle([
 *     'name' => '  my product  ',
 *     'price' => '99.99',
 *     'description' => '<p>Product description</p>',
 * ]);
 * @example
 * // Sanitizing API request data:
 * class ProcessApiRequest extends Actions
 * {
 *     use AsSanitizer;
 *
 *     public function handle(array $requestData): array
 *     {
 *         // Sanitize API input
 *         $clean = $this->sanitize($requestData);
 *
 *         // Process API request
 *         return $this->processRequest($clean);
 *     }
 *
 *     public function getSanitizationRules(): array
 *     {
 *         return [
 *             'api_key' => 'trim',
 *             'query' => 'trim|strip_tags',
 *             'filters.*' => 'trim',  // Sanitize all filter values
 *         ];
 *     }
 *
 *     protected function processRequest(array $data): array
 *     {
 *         // Process sanitized request
 *         return ['success' => true, 'data' => $data];
 *     }
 * }
 *
 * // Usage in API controller:
 * class ApiController extends Controller
 * {
 *     public function search(Request $request)
 *     {
 *         $action = ProcessApiRequest::make();
 *         $result = $action->handle($request->all());
 *
 *         return response()->json($result);
 *     }
 * }
 * @example
 * // Combining multiple sanitization rules:
 * class ProcessUserProfile extends Actions
 * {
 *     use AsSanitizer;
 *
 *     public function handle(array $profile): array
 *     {
 *         return $this->sanitize($profile);
 *     }
 *
 *     public function getSanitizationRules(): array
 *     {
 *         return [
 *             // Multiple rules per field
 *             'email' => 'trim|lowercase|strip_tags',
 *             'name' => 'trim|title|strip_tags',
 *             'website' => 'trim|lowercase',
 *             'bio' => 'strip_tags|trim|htmlspecialchars',
 *         ];
 *     }
 * }
 *
 * // Usage:
 * $action = ProcessUserProfile::make();
 * $clean = $action->handle([
 *     'email' => '  JOHN@EXAMPLE.COM  ',
 *     'name' => '<b>John Doe</b>',
 *     'website' => 'HTTPS://EXAMPLE.COM',
 *     'bio' => '<p>My bio & info</p>',
 * ]);
 * // Rules are applied in order: trim -> lowercase -> strip_tags
 */
trait AsSanitizer
{
    protected function sanitize(mixed $data): mixed
    {
        if (is_array($data)) {
            return $this->sanitizeArray($data);
        }

        if (is_string($data)) {
            return $this->sanitizeString($data);
        }

        return $data;
    }

    protected function sanitizeArray(array $data): array
    {
        $rules = $this->getSanitizationRules();
        $sanitized = [];

        foreach ($data as $key => $value) {
            $sanitizedKey = $this->sanitizeString($key);
            $sanitizedValue = $value;

            if (isset($rules[$key])) {
                $sanitizedValue = $this->applySanitizationRules($value, $rules[$key]);
            } elseif (is_string($value)) {
                $sanitizedValue = $this->sanitizeString($value);
            } elseif (is_array($value)) {
                $sanitizedValue = $this->sanitizeArray($value);
            }

            $sanitized[$sanitizedKey] = $sanitizedValue;
        }

        return $sanitized;
    }

    protected function sanitizeString(string $value): string
    {
        // Default sanitization
        $value = trim($value);
        $value = strip_tags($value);
        $value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

        return $value;
    }

    protected function applySanitizationRules(mixed $value, string|array $rules): mixed
    {
        if (is_string($rules)) {
            $rules = explode('|', $rules);
        }

        foreach ($rules as $rule) {
            $value = match ($rule) {
                'trim' => trim($value),
                'lowercase' => strtolower($value),
                'uppercase' => strtoupper($value),
                'title' => Str::title($value),
                'strip_tags' => strip_tags($value),
                'htmlspecialchars' => htmlspecialchars($value, ENT_QUOTES, 'UTF-8'),
                'htmlentities' => htmlentities($value, ENT_QUOTES, 'UTF-8'),
                default => $value,
            };
        }

        return $value;
    }

    protected function getSanitizationRules(): array
    {
        return $this->hasMethod('getSanitizationRules')
            ? $this->callMethod('getSanitizationRules')
            : [];
    }
}
