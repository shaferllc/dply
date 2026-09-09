<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Services\Sites\DotEnvFileParser;

/**
 * Map a pasted .env snippet onto the selected connected-app form fields.
 * Other providers' keys are ignored.
 */
final class ConnectedAppEnvPaste
{
    /**
     * @return array<string, string>
     */
    public static function fromBlob(string $provider, string $blob): array
    {
        $parsed = app(DotEnvFileParser::class)->parse($blob);

        return self::fieldsFor($provider, is_array($parsed['variables'] ?? null) ? $parsed['variables'] : []);
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, string>
     */
    public static function fieldsFor(string $provider, array $variables): array
    {
        $vars = [];
        foreach ($variables as $key => $value) {
            $key = strtoupper(trim((string) $key));
            $value = trim((string) $value);
            if ($key !== '' && $value !== '') {
                $vars[$key] = $value;
            }
        }

        $fields = match (strtolower(trim($provider))) {
            'slack' => [
                'bot_token' => $vars['SLACK_BOT_TOKEN'] ?? $vars['SLACK_BOT_USER_OAUTH_TOKEN'] ?? '',
                'webhook_url' => $vars['SLACK_WEBHOOK_URL'] ?? '',
                'channel' => $vars['SLACK_BOT_USER_DEFAULT_CHANNEL'] ?? $vars['SLACK_DEFAULT_CHANNEL'] ?? '',
            ],
            'discord' => [
                'bot_token' => $vars['DISCORD_BOT_TOKEN'] ?? '',
                'webhook_url' => $vars['DISCORD_WEBHOOK_URL'] ?? '',
            ],
            'telegram' => [
                'bot_token' => $vars['TELEGRAM_BOT_TOKEN'] ?? '',
                'chat_id' => $vars['TELEGRAM_CHAT_ID'] ?? '',
            ],
            'google_drive' => [
                'client_id' => $vars['GOOGLE_DRIVE_CLIENT_ID'] ?? '',
                'client_secret' => $vars['GOOGLE_DRIVE_CLIENT_SECRET'] ?? '',
                'refresh_token' => $vars['GOOGLE_DRIVE_REFRESH_TOKEN'] ?? '',
                'folder_id' => $vars['GOOGLE_DRIVE_FOLDER'] ?? $vars['GOOGLE_DRIVE_FOLDER_ID'] ?? '',
            ],
            'dropbox' => [
                'access_token' => $vars['DROPBOX_ACCESS_TOKEN'] ?? '',
                'app_key' => $vars['DROPBOX_APP_KEY'] ?? '',
                'app_secret' => $vars['DROPBOX_APP_SECRET'] ?? '',
            ],
            default => [],
        };

        return array_filter($fields, static fn (string $value): bool => $value !== '');
    }
}
