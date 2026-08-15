<?php

namespace Tests\Unit\PagerDutyMessageTest;

use App\Modules\Notifications\Channels\PagerDuty\PagerDutyMessage;
use App\Modules\Notifications\Services\PagerDutyClient;

/**
 * API-parity guard for laravel-notification-channels/pagerduty.
 *
 * We reimplemented that package rather than depending on it (it caps at
 * illuminate/* ^12.0 and this app is on Laravel 13). These tests pin the public
 * surface to upstream so code written against the package's docs keeps working.
 */
test('a default message matches the upstream envelope', function () {
    $payload = PagerDutyMessage::create()->setSummary('Site down')->toArray();

    // Upstream defaults: trigger, hostname source, critical severity — and the
    // payload nested under `payload` with the envelope collapsed alongside.
    expect($payload['event_action'])->toBe('trigger');
    expect($payload['payload']['severity'])->toBe('critical');
    expect($payload['payload']['source'])->toBe(gethostname());
    expect($payload['payload']['summary'])->toBe('Site down');
});

test('the package README example produces the documented shape', function () {
    $message = PagerDutyMessage::create()
        ->setSummary('There was an error with your site in the web component.');

    $message->setRoutingKey('99dc10c97a6e43c387bbc4f877c794ef');

    $payload = $message->toArray();

    expect($payload['routing_key'])->toBe('99dc10c97a6e43c387bbc4f877c794ef');
    expect($payload['event_action'])->toBe('trigger');
    expect($payload)->toHaveKey('payload');
});

test('every upstream setter lands in the right bucket', function () {
    $payload = PagerDutyMessage::create()
        ->setRoutingKey('key')
        ->setDedupKey('dedup')
        ->setSummary('summary')
        ->setSource('web-1')
        ->setSeverity('warning')
        ->setTimestamp('2026-08-14T00:00:00+00:00')
        ->setComponent('nginx')
        ->setGroup('prod')
        ->setClass('disk')
        ->addCustomDetail('used', '92%')
        ->toArray();

    // Envelope
    expect($payload['routing_key'])->toBe('key');
    expect($payload['dedup_key'])->toBe('dedup');
    // Alert body
    expect($payload['payload']['summary'])->toBe('summary');
    expect($payload['payload']['source'])->toBe('web-1');
    expect($payload['payload']['severity'])->toBe('warning');
    expect($payload['payload']['timestamp'])->toBe('2026-08-14T00:00:00+00:00');
    expect($payload['payload']['component'])->toBe('nginx');
    expect($payload['payload']['group'])->toBe('prod');
    expect($payload['payload']['class'])->toBe('disk');
    expect($payload['payload']['custom_details']['used'])->toBe('92%');
});

test('resolve flips the event action', function () {
    expect(PagerDutyMessage::create()->resolve()->toArray()['event_action'])->toBe('resolve');
    // dply extension — Events API v2 supports it, upstream's builder does not.
    expect(PagerDutyMessage::create()->acknowledge()->toArray()['event_action'])->toBe('acknowledge');
});

test('summary is capped rather than sent over the limit', function () {
    $payload = PagerDutyMessage::create()->setSummary(str_repeat('x', 2000))->toArray();

    expect(mb_strlen($payload['payload']['summary']))->toBe(PagerDutyMessage::SUMMARY_MAX_LENGTH);
});

test('a trigger is invalid without a summary but a resolve only needs a dedup key', function () {
    expect(PagerDutyMessage::create()->isValid())->toBeFalse();
    expect(PagerDutyMessage::create()->setSummary('x')->isValid())->toBeTrue();

    expect(PagerDutyMessage::create()->resolve()->isValid())->toBeFalse();
    expect(PagerDutyMessage::create()->resolve()->setDedupKey('d')->isValid())->toBeTrue();
});

test('region rides alongside the payload not inside it', function () {
    $message = PagerDutyMessage::create()->setSummary('x')->region('eu');

    expect($message->getRegion())->toBe('eu');
    expect($message->toArray())->not->toHaveKey('region');
});

test('links images and client are envelope fields', function () {
    $payload = PagerDutyMessage::create()
        ->setSummary('x')
        ->setClient('dply')
        ->setClientUrl('https://dply.test/servers/1')
        ->addLink('https://dply.test/servers/1', 'Open in Dply')
        ->addImage('https://dply.test/logo.png')
        ->toArray();

    expect($payload['client'])->toBe('dply');
    expect($payload['client_url'])->toBe('https://dply.test/servers/1');
    expect($payload['links'][0])->toBe(['href' => 'https://dply.test/servers/1', 'text' => 'Open in Dply']);
    // Null href/alt are dropped rather than sent as nulls.
    expect($payload['images'][0])->toBe(['src' => 'https://dply.test/logo.png']);
});

test('setCustomDetails skips empties and stringifies scalars', function () {
    $payload = PagerDutyMessage::create()
        ->setSummary('x')
        ->setCustomDetails(['exit_code' => 137, 'note' => '', 'missing' => null, 'ok' => 'yes'])
        ->toArray();

    expect($payload['payload']['custom_details'])->toBe(['exit_code' => '137', 'ok' => 'yes']);
});

test('an unknown dply severity becomes info, never critical', function () {
    // A gap in the mapping must not invent an emergency.
    expect(PagerDutyMessage::severityFromEventSeverity('critical'))->toBe('critical');
    expect(PagerDutyMessage::severityFromEventSeverity('failure'))->toBe('error');
    expect(PagerDutyMessage::severityFromEventSeverity('warn'))->toBe('warning');
    expect(PagerDutyMessage::severityFromEventSeverity('notice'))->toBe('info');
    expect(PagerDutyMessage::severityFromEventSeverity(null))->toBe('info');
});

test('unknown regions fall back to us', function () {
    expect(PagerDutyClient::normalizeRegion('EU'))->toBe('eu');
    expect(PagerDutyClient::normalizeRegion('apac'))->toBe('us');
    expect(PagerDutyClient::normalizeRegion(''))->toBe('us');
});

test('error copy names the routing key for the 400 that really means a bad key', function () {
    // PagerDuty answers a wrong/wrong-region key with a 400 mentioning
    // routing_key, not a 401 — the status misleads, so the copy must not.
    expect(PagerDutyClient::describeError("Event object is invalid: 'routing_key' is invalid"))
        ->toContain('integration key');
    expect(PagerDutyClient::describeError('http_429'))->toContain('rate limiting');
    expect(PagerDutyClient::describeError('not_configured'))->toContain('integration key');
    expect(PagerDutyClient::describeError('something new'))->toContain('something new');
});
