<?php

declare(strict_types=1);

namespace App\Modules\Database\Support;

use App\Models\CloudDatabase;

/**
 * Registry of BYO serverless-database vendors surfaced in the provision modal.
 *
 * Each entry is region-agnostic (the customer connects their own vendor API
 * key and picks from the vendor's regions). The `key` doubles as the placement
 * key in the modal and the CloudDatabase.backend value, so it resolves through
 * {@see \App\Modules\Database\Backends\DatabaseRouter::backend()}. `provider`
 * is the ProviderCredential.provider string the API key is stored under.
 *
 * `account_label` (when set) makes the modal render a second credential input —
 * Upstash needs the account email for Basic auth; PlanetScale/Supabase take an
 * optional org slug (auto-discovered when left blank). `account_required`
 * forces it.
 *
 * ⚠️ Region slugs + API field shapes follow each vendor's published docs and
 * are not verified against live accounts — treat the first real run as the
 * validation.
 */
final class ServerlessDatabaseVendors
{
    /**
     * @return list<array{key: string, label: string, provider: string, engines: list<string>, account_label: ?string, account_required: bool, regions: list<array{value: string, label: string}>}>
     */
    public static function all(): array
    {
        return [
            [
                'key' => CloudDatabase::BACKEND_NEON,
                'label' => 'Neon',
                'provider' => 'neon',
                'engines' => [CloudDatabase::ENGINE_POSTGRES],
                'account_label' => null,
                'account_required' => false,
                'regions' => [
                    ['value' => 'aws-us-east-1', 'label' => 'AWS US East (N. Virginia)'],
                    ['value' => 'aws-us-east-2', 'label' => 'AWS US East (Ohio)'],
                    ['value' => 'aws-us-west-2', 'label' => 'AWS US West (Oregon)'],
                    ['value' => 'aws-eu-central-1', 'label' => 'AWS Europe (Frankfurt)'],
                    ['value' => 'aws-eu-west-2', 'label' => 'AWS Europe (London)'],
                    ['value' => 'aws-ap-southeast-1', 'label' => 'AWS Asia Pacific (Singapore)'],
                    ['value' => 'aws-ap-southeast-2', 'label' => 'AWS Asia Pacific (Sydney)'],
                ],
            ],
            [
                'key' => CloudDatabase::BACKEND_PLANETSCALE,
                'label' => 'PlanetScale',
                'provider' => 'planetscale',
                'engines' => [CloudDatabase::ENGINE_MYSQL],
                'account_label' => 'Organization (optional)',
                'account_required' => false,
                'regions' => [
                    ['value' => 'us-east', 'label' => 'AWS US East (Virginia)'],
                    ['value' => 'us-west', 'label' => 'AWS US West (Oregon)'],
                    ['value' => 'eu-west', 'label' => 'AWS Europe (Ireland)'],
                    ['value' => 'eu-central', 'label' => 'AWS Europe (Frankfurt)'],
                    ['value' => 'ap-southeast', 'label' => 'AWS Asia Pacific (Singapore)'],
                    ['value' => 'ap-northeast', 'label' => 'AWS Asia Pacific (Tokyo)'],
                ],
            ],
            [
                'key' => CloudDatabase::BACKEND_SUPABASE,
                'label' => 'Supabase',
                'provider' => 'supabase',
                'engines' => [CloudDatabase::ENGINE_POSTGRES],
                'account_label' => 'Organization ID (optional)',
                'account_required' => false,
                'regions' => [
                    ['value' => 'us-east-1', 'label' => 'AWS US East (N. Virginia)'],
                    ['value' => 'us-west-1', 'label' => 'AWS US West (N. California)'],
                    ['value' => 'eu-west-1', 'label' => 'AWS Europe (Ireland)'],
                    ['value' => 'eu-central-1', 'label' => 'AWS Europe (Frankfurt)'],
                    ['value' => 'ap-southeast-1', 'label' => 'AWS Asia Pacific (Singapore)'],
                    ['value' => 'ap-northeast-1', 'label' => 'AWS Asia Pacific (Tokyo)'],
                ],
            ],
            [
                'key' => CloudDatabase::BACKEND_UPSTASH,
                'label' => 'Upstash',
                'provider' => 'upstash',
                'engines' => [CloudDatabase::ENGINE_REDIS],
                'account_label' => 'Account email',
                'account_required' => true,
                'regions' => [
                    ['value' => 'us-east-1', 'label' => 'AWS US East (N. Virginia)'],
                    ['value' => 'us-west-1', 'label' => 'AWS US West (N. California)'],
                    ['value' => 'eu-west-1', 'label' => 'AWS Europe (Ireland)'],
                    ['value' => 'eu-central-1', 'label' => 'AWS Europe (Frankfurt)'],
                    ['value' => 'ap-northeast-1', 'label' => 'AWS Asia Pacific (Tokyo)'],
                    ['value' => 'ap-southeast-1', 'label' => 'AWS Asia Pacific (Singapore)'],
                ],
            ],
        ];
    }

    /** @return list<string> the placement/backend keys for all serverless vendors. */
    public static function keys(): array
    {
        return array_map(static fn (array $v): string => $v['key'], self::all());
    }

    public static function isServerless(string $key): bool
    {
        return in_array($key, self::keys(), true);
    }

    /**
     * @return array{key: string, label: string, provider: string, engines: list<string>, account_label: ?string, account_required: bool, regions: list<array{value: string, label: string}>}|null
     */
    public static function find(string $key): ?array
    {
        foreach (self::all() as $vendor) {
            if ($vendor['key'] === $key) {
                return $vendor;
            }
        }

        return null;
    }
}
