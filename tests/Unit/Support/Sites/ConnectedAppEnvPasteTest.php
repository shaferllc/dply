<?php

declare(strict_types=1);

use App\Support\Sites\ConnectedAppEnvPaste;

test('slack paste fills only slack fields and ignores other keys', function () {
    $fields = ConnectedAppEnvPaste::fromBlob('slack', <<<'ENV'
APP_KEY=base64:nope
SLACK_BOT_TOKEN=xoxb-test
SLACK_WEBHOOK_URL=https://hooks.slack.com/services/T/B/X
SLACK_BOT_USER_DEFAULT_CHANNEL=#ops
DISCORD_BOT_TOKEN=should-not-fill
ENV);

    expect($fields)->toBe([
        'bot_token' => 'xoxb-test',
        'webhook_url' => 'https://hooks.slack.com/services/T/B/X',
        'channel' => '#ops',
    ]);
});

test('slack prefers SLACK_BOT_TOKEN over the oauth alias', function () {
    $fields = ConnectedAppEnvPaste::fromBlob('slack', <<<'ENV'
SLACK_BOT_USER_OAUTH_TOKEN=xoxb-oauth
SLACK_BOT_TOKEN=xoxb-direct
ENV);

    expect($fields['bot_token'] ?? null)->toBe('xoxb-direct');
});

test('discord paste does not pick up slack keys', function () {
    $fields = ConnectedAppEnvPaste::fromBlob('discord', <<<'ENV'
SLACK_BOT_TOKEN=xoxb-test
DISCORD_WEBHOOK_URL=https://discord.com/api/webhooks/1/2
ENV);

    expect($fields)->toBe([
        'webhook_url' => 'https://discord.com/api/webhooks/1/2',
    ]);
});

test('empty or unrelated paste returns no fields', function () {
    expect(ConnectedAppEnvPaste::fromBlob('slack', "APP_KEY=x\n"))->toBe([])
        ->and(ConnectedAppEnvPaste::fromBlob('telegram', ''))->toBe([]);
});
