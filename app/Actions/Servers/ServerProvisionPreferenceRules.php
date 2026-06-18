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
            'server_role' => ['required', Rule::in(array_column($o['server_roles'] ?? [], 'id'))],
            'cache_service' => ['required', Rule::in(array_column($o['cache_services'] ?? [], 'id'))],
            'webserver' => ['required', Rule::in(array_column($o['webservers'] ?? [], 'id'))],
            'php_version' => ['required', Rule::in(array_column($o['php_versions'] ?? [], 'id'))],
            'database' => ['required', Rule::in(array_column($o['databases'] ?? [], 'id'))],
        ];
    }
}
