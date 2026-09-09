<?php

declare(strict_types=1);

namespace App\Modules\Deploy\Services\Concerns;

use App\Models\ConnectedAppCredential;
use App\Models\Site;
use App\Models\SiteBinding;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Attach the `connected_app` binding — generic SaaS keys (Slack, Discord,
 * Telegram, Google Drive, Dropbox) injected at deploy. Same shape as
 * {@see ManagesAiBindings}: saved org credential or typed form.
 */
trait ManagesConnectedAppBindings
{
    public const CONNECTED_APP_PROVIDERS = [
        'slack',
        'discord',
        'telegram',
        'google_drive',
        'dropbox',
    ];

    /**
     * @param  array<string, mixed>  $params
     */
    private function attachConnectedApp(Site $site, array $params): SiteBinding
    {
        $provider = strtolower(trim((string) ($params['provider'] ?? '')));
        if (! in_array($provider, self::CONNECTED_APP_PROVIDERS, true)) {
            throw new InvalidArgumentException(__('Unsupported connected app.'));
        }

        $creds = $this->resolveConnectedAppCredentials($site, $provider, $params);
        $this->validateConnectedAppCredentials($provider, $creds);

        $binding = $this->persistInstanceBinding($site, 'connected_app', [
            'mode' => 'attach_existing',
            'status' => SiteBinding::STATUS_CONFIGURED,
            'name' => $this->connectedAppLabel($provider),
            'target_type' => 'connected_app',
            'target_id' => null,
            'injected_env' => $this->connectedAppEnv($provider, $creds),
            'config' => ['provider' => $provider],
            'last_error' => null,
        ], false, trim((string) ($params['binding_id'] ?? '')));

        $this->maybeSaveConnectedAppCredential($site, $provider, $params, $creds);

        return $binding;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, string>
     */
    private function resolveConnectedAppCredentials(Site $site, string $provider, array $params): array
    {
        $credentialId = trim((string) ($params['credential_id'] ?? ''));
        if ($credentialId !== '') {
            $cred = ConnectedAppCredential::query()
                ->where('organization_id', $site->organization_id)
                ->where('provider', $provider)
                ->whereKey($credentialId)
                ->first();

            if (! $cred instanceof ConnectedAppCredential) {
                throw new InvalidArgumentException(__('That saved app credential is no longer available.'));
            }

            return is_array($cred->credentials) ? $cred->credentials : [];
        }

        $keys = match ($provider) {
            'slack' => ['bot_token', 'webhook_url', 'channel'],
            'discord' => ['bot_token', 'webhook_url'],
            'telegram' => ['bot_token', 'chat_id'],
            'google_drive' => ['client_id', 'client_secret', 'refresh_token', 'folder_id'],
            'dropbox' => ['access_token', 'app_key', 'app_secret'],
            default => [],
        };

        $creds = [];
        foreach ($keys as $key) {
            $value = trim((string) ($params[$key] ?? ''));
            if ($value !== '') {
                $creds[$key] = $value;
            }
        }

        return $creds;
    }

    /** @param  array<string, mixed>  $creds */
    private function validateConnectedAppCredentials(string $provider, array $creds): void
    {
        match ($provider) {
            'slack' => (($creds['bot_token'] ?? '') === '' && ($creds['webhook_url'] ?? '') === '')
                ? throw new InvalidArgumentException(__('Enter a Slack bot token or incoming webhook URL.'))
                : null,
            'discord' => (($creds['bot_token'] ?? '') === '' && ($creds['webhook_url'] ?? '') === '')
                ? throw new InvalidArgumentException(__('Enter a Discord bot token or webhook URL.'))
                : null,
            'telegram' => ($creds['bot_token'] ?? '') === ''
                ? throw new InvalidArgumentException(__('A Telegram bot token is required.'))
                : null,
            'google_drive' => ($creds['client_id'] ?? '') === '' || ($creds['client_secret'] ?? '') === '' || ($creds['refresh_token'] ?? '') === ''
                ? throw new InvalidArgumentException(__('Google Drive needs a client ID, client secret, and refresh token.'))
                : null,
            'dropbox' => ($creds['access_token'] ?? '') === ''
                ? throw new InvalidArgumentException(__('A Dropbox access token is required.'))
                : null,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $creds
     * @return array<string, string>
     */
    private function connectedAppEnv(string $provider, array $creds): array
    {
        $token = (string) ($creds['bot_token'] ?? '');

        return array_filter(match ($provider) {
            'slack' => [
                'SLACK_BOT_USER_OAUTH_TOKEN' => $token,
                'SLACK_BOT_TOKEN' => $token,
                'SLACK_WEBHOOK_URL' => (string) ($creds['webhook_url'] ?? ''),
                'SLACK_BOT_USER_DEFAULT_CHANNEL' => (string) ($creds['channel'] ?? ''),
            ],
            'discord' => [
                'DISCORD_BOT_TOKEN' => $token,
                'DISCORD_WEBHOOK_URL' => (string) ($creds['webhook_url'] ?? ''),
            ],
            'telegram' => [
                'TELEGRAM_BOT_TOKEN' => $token,
                'TELEGRAM_CHAT_ID' => (string) ($creds['chat_id'] ?? ''),
            ],
            'google_drive' => [
                'GOOGLE_DRIVE_CLIENT_ID' => (string) ($creds['client_id'] ?? ''),
                'GOOGLE_DRIVE_CLIENT_SECRET' => (string) ($creds['client_secret'] ?? ''),
                'GOOGLE_DRIVE_REFRESH_TOKEN' => (string) ($creds['refresh_token'] ?? ''),
                'GOOGLE_DRIVE_FOLDER' => (string) ($creds['folder_id'] ?? ''),
            ],
            'dropbox' => [
                'DROPBOX_ACCESS_TOKEN' => (string) ($creds['access_token'] ?? ''),
                'DROPBOX_APP_KEY' => (string) ($creds['app_key'] ?? ''),
                'DROPBOX_APP_SECRET' => (string) ($creds['app_secret'] ?? ''),
            ],
            default => [],
        }, fn (string $value): bool => $value !== '');
    }

    public function connectedAppLabel(string $provider): string
    {
        return match ($provider) {
            'slack' => 'Slack',
            'discord' => 'Discord',
            'telegram' => 'Telegram',
            'google_drive' => 'Google Drive',
            'dropbox' => 'Dropbox',
            default => ucfirst($provider),
        };
    }

    /**
     * Env keys this one attached provider owns (not every app — Slack must
     * not strip Drive keys from a sibling binding).
     *
     * @return list<string>
     */
    private function connectedAppOwnedEnvKeys(SiteBinding $binding): array
    {
        $provider = (string) (((array) $binding->config)['provider'] ?? '');

        return match ($provider) {
            'slack' => [
                'SLACK_BOT_USER_OAUTH_TOKEN', 'SLACK_BOT_TOKEN',
                'SLACK_WEBHOOK_URL', 'SLACK_BOT_USER_DEFAULT_CHANNEL',
            ],
            'discord' => ['DISCORD_BOT_TOKEN', 'DISCORD_WEBHOOK_URL'],
            'telegram' => ['TELEGRAM_BOT_TOKEN', 'TELEGRAM_CHAT_ID'],
            'google_drive' => [
                'GOOGLE_DRIVE_CLIENT_ID', 'GOOGLE_DRIVE_CLIENT_SECRET',
                'GOOGLE_DRIVE_REFRESH_TOKEN', 'GOOGLE_DRIVE_FOLDER',
            ],
            'dropbox' => ['DROPBOX_ACCESS_TOKEN', 'DROPBOX_APP_KEY', 'DROPBOX_APP_SECRET'],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $creds
     */
    private function maybeSaveConnectedAppCredential(Site $site, string $provider, array $params, array $creds): void
    {
        if (! (bool) ($params['save_credential'] ?? false)) {
            return;
        }
        if (trim((string) ($params['credential_id'] ?? '')) !== '') {
            return;
        }
        if ($creds === []) {
            return;
        }

        $name = trim((string) ($params['credential_name'] ?? ''));
        if ($name === '') {
            $name = $this->connectedAppLabel($provider).' '.__('keys');
        }

        ConnectedAppCredential::query()->create([
            'organization_id' => $site->organization_id,
            'created_by_user_id' => auth()->id(),
            'provider' => $provider,
            'name' => Str::limit($name, 120, ''),
            'credentials' => $creds,
        ]);
    }
}
