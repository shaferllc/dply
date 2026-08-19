<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Notifications\Services\PlatformNotificationApps;
use App\Modules\Notifications\Services\TelegramBotClient;
use Illuminate\Console\Command;

/**
 * Point Telegram at this deployment's /hooks/telegram endpoint.
 *
 * Telegram bots are push-only for updates: nothing arrives until a webhook is
 * registered, so "Connect Telegram" silently does nothing until this has been
 * run once per deployment (and again whenever the public URL changes — which,
 * with a local tunnel, is most days).
 */
class TelegramSetWebhookCommand extends Command
{
    protected $signature = 'telegram:set-webhook
        {--url= : Public HTTPS URL for the webhook (defaults to TELEGRAM_WEBHOOK_URL, else APP_URL + /hooks/telegram)}
        {--show : Print the currently registered webhook instead of setting one}
        {--delete : Unregister the webhook}';

    protected $description = 'Register (or inspect) the Telegram bot webhook for notification channels';

    public function handle(): int
    {
        if (! TelegramBotClient::botConfigured()) {
            $this->error('No Telegram bot token is configured. Save one under Admin → Connections, or set TELEGRAM_BOT_TOKEN.');

            return self::FAILURE;
        }

        $client = TelegramBotClient::make();

        if ($this->option('show')) {
            $info = $client->webhookInfo();
            if (! $info['ok']) {
                $this->error(TelegramBotClient::describeError($info['error']));

                return self::FAILURE;
            }

            $this->line('URL:            '.($info['url'] !== '' ? $info['url'] : '(none registered)'));
            $this->line('Pending updates: '.$info['pending']);
            if ($info['last_error'] !== '') {
                // The single most useful field when connects silently fail:
                // Telegram records why its last delivery attempt failed.
                $this->warn('Last error:     '.$info['last_error']);
            }

            return self::SUCCESS;
        }

        if ($this->option('delete')) {
            $result = $client->deleteWebhook();
            if (! $result['ok']) {
                $this->error(TelegramBotClient::describeError($result['error']));

                return self::FAILURE;
            }

            $this->info('Telegram webhook removed.');

            return self::SUCCESS;
        }

        $secret = PlatformNotificationApps::telegram()['webhook_secret'];
        if ($secret === '') {
            $this->error('No Telegram webhook secret is configured. Save one under Admin → Connections, or set TELEGRAM_WEBHOOK_SECRET.');

            return self::FAILURE;
        }

        $url = $this->resolveUrl();

        if (! str_starts_with($url, 'https://')) {
            // Telegram refuses plain HTTP outright, and a .test host is not
            // publicly resolvable — both fail at registration, not at delivery.
            $this->error('Telegram requires a public HTTPS URL. Got: '.$url);
            $this->line('Use a tunnel (Expose/ngrok) locally and pass --url, or set TELEGRAM_WEBHOOK_URL.');

            return self::FAILURE;
        }

        $result = $client->setWebhook($url, $secret);
        if (! $result['ok']) {
            $this->error(TelegramBotClient::describeError($result['error']));

            return self::FAILURE;
        }

        $this->info('Telegram webhook registered: '.$url);

        $me = $client->me();
        if ($me['ok'] && $me['username'] !== '') {
            $this->line('Bot: @'.$me['username']);
        }

        return self::SUCCESS;
    }

    private function resolveUrl(): string
    {
        $option = $this->option('url');
        if (is_string($option) && $option !== '') {
            return rtrim($option, '/');
        }

        return PlatformNotificationApps::telegramWebhookUrl();
    }
}
