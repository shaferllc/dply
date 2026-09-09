<?php

namespace Tests\Unit\MicrosoftTeamsMessageTest;

use App\Modules\Notifications\Channels\MicrosoftTeams\MicrosoftTeamsMessage;
use App\Modules\Notifications\Services\MicrosoftTeamsClient;

test('the envelope is a Workflows message with one adaptive card attachment', function () {
    $payload = MicrosoftTeamsMessage::create('Body text')->title('Disk almost full')->toArray();

    // Posting a bare Adaptive Card gets a 202 and renders nothing, so the
    // envelope shape is the thing worth pinning.
    expect($payload['type'])->toBe('message');
    expect($payload['attachments'])->toHaveCount(1);
    expect($payload['attachments'][0]['contentType'])->toBe('application/vnd.microsoft.card.adaptive');

    $card = $payload['attachments'][0]['content'];
    expect($card['type'])->toBe('AdaptiveCard');
    expect($card['version'])->toBe('1.4');
    expect($card['msteams']['width'])->toBe('Full');
});

test('title becomes a bold heading and content becomes wrapped text blocks', function () {
    $body = MicrosoftTeamsMessage::create()
        ->title('Disk almost full')
        ->content('web-1 is at 92%.')
        ->content('Second paragraph.')
        ->toArray()['attachments'][0]['content']['body'];

    expect($body[0])->toMatchArray(['type' => 'TextBlock', 'text' => 'Disk almost full', 'weight' => 'Bolder', 'wrap' => true]);
    expect($body[1])->toMatchArray(['type' => 'TextBlock', 'text' => 'web-1 is at 92%.', 'wrap' => true]);
    expect($body[2]['text'])->toBe('Second paragraph.');
});

test('empty content is dropped rather than emitting a blank block', function () {
    $body = MicrosoftTeamsMessage::create()->title('T')->content('')->content('   ')->toArray()['attachments'][0]['content']['body'];

    expect($body)->toHaveCount(1);
});

test('facts become a FactSet and buttons become OpenUrl actions', function () {
    $card = MicrosoftTeamsMessage::create()
        ->title('Deploy failed')
        ->facts(['Site' => 'acme.test', 'Status' => 'failed', 'Skipped' => '', 'Null' => null])
        ->button('Open in Dply', 'https://dply.test/sites/1')
        ->toArray()['attachments'][0]['content'];

    $factSet = collect($card['body'])->firstWhere('type', 'FactSet');
    expect($factSet['facts'])->toBe([
        ['title' => 'Site', 'value' => 'acme.test'],
        ['title' => 'Status', 'value' => 'failed'],
    ]);

    expect($card['actions'][0])->toBe([
        'type' => 'Action.OpenUrl',
        'title' => 'Open in Dply',
        'url' => 'https://dply.test/sites/1',
    ]);
});

test('summary falls back to the title for the activity feed', function () {
    // Teams shows this where the card body is not rendered; without it the feed
    // entry reads "sent a card".
    expect(MicrosoftTeamsMessage::create()->title('Disk almost full')->toArray()['summary'])
        ->toBe('Disk almost full');

    expect(MicrosoftTeamsMessage::create()->title('T')->summary('Explicit')->toArray()['summary'])
        ->toBe('Explicit');
});

test('semantic type names map onto adaptive card colour tokens', function () {
    $colorOf = fn (string $type): string => MicrosoftTeamsMessage::create()
        ->title('T')->type($type)->toArray()['attachments'][0]['content']['body'][0]['color'];

    expect($colorOf('success'))->toBe('good');
    expect($colorOf('error'))->toBe('attention');
    expect($colorOf('warning'))->toBe('warning');
    expect($colorOf('info'))->toBe('accent');
    // Adaptive Cards have no arbitrary colour, so hex degrades rather than breaks.
    expect($colorOf('#ff0000'))->toBe('default');
});

test('a card needs a title or content to be valid', function () {
    expect(MicrosoftTeamsMessage::create()->isValid())->toBeFalse();
    expect(MicrosoftTeamsMessage::create()->title('T')->isValid())->toBeTrue();
    expect(MicrosoftTeamsMessage::create('body')->isValid())->toBeTrue();
});

test('workflow URLs are told apart from retired connector URLs', function () {
    expect(MicrosoftTeamsClient::classifyUrl('https://prod-27.westus.logic.azure.com:443/workflows/abc/triggers/manual/paths/invoke'))
        ->toBe(MicrosoftTeamsClient::KIND_WORKFLOW);

    // The retired shape. Microsoft turned these off between 18–22 May 2026.
    expect(MicrosoftTeamsClient::classifyUrl('https://acme.webhook.office.com/webhookb2/abc@def/IncomingWebhook/ghi/jkl'))
        ->toBe(MicrosoftTeamsClient::KIND_CONNECTOR);
    expect(MicrosoftTeamsClient::isRetiredConnectorUrl('https://acme.webhook.office.com/webhookb2/x'))->toBeTrue();

    // An unrecognised host is NOT rejected — self-hosters proxy these.
    expect(MicrosoftTeamsClient::classifyUrl('https://teams-proxy.internal.acme.test/hook'))
        ->toBe(MicrosoftTeamsClient::KIND_UNKNOWN);
    expect(MicrosoftTeamsClient::isRetiredConnectorUrl('https://teams-proxy.internal.acme.test/hook'))->toBeFalse();

    expect(MicrosoftTeamsClient::classifyUrl(null))->toBe(MicrosoftTeamsClient::KIND_UNKNOWN);
    expect(MicrosoftTeamsClient::classifyUrl('not a url'))->toBe(MicrosoftTeamsClient::KIND_UNKNOWN);
});

test('the connector error explains the retirement rather than blaming the URL', function () {
    expect(MicrosoftTeamsClient::describeError('retired_connector'))
        ->toContain('retired')
        ->toContain('Workflows');

    expect(MicrosoftTeamsClient::describeError('http_403'))->toContain('flow');
    expect(MicrosoftTeamsClient::describeError('something else'))->toContain('something else');
});
