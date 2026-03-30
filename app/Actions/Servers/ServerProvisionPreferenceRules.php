<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use Illuminate\Validation\Rule;

/**
 * Validation rules for server create “stack” fields (config-driven, filtered by provider / credentials).
 */
final class ServerProvisionPreferenceRules
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(string $formType, bool $hasLinkedCredentialForProvider, string $serverRole): array
    {
        $o = FilterServerProvisionOptionsForCreateForm::run($formType, $hasLinkedCredentialForProvider, $serverRole);

        return [
            'server_role' => ['required', Rule::in(collect($o['server_roles'] ?? [])->pluck('id')->all())],
            'cache_service' => ['required', Rule::in(collect($o['cache_services'] ?? [])->pluck('id')->all())],
            'webserver' => ['required', Rule::in(collect($o['webservers'] ?? [])->pluck('id')->all())],
            'php_version' => ['required', Rule::in(collect($o['php_versions'] ?? [])->pluck('id')->all())],
            'database' => ['required', Rule::in(collect($o['databases'] ?? [])->pluck('id')->all())],
        ];
    }
}
