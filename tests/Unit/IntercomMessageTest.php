<?php

namespace Tests\Unit\IntercomMessageTest;

use App\Modules\Notifications\Channels\Intercom\IntercomMessage;
use App\Modules\Notifications\Services\IntercomClient;

/**
 * API-parity guard for laravel-notification-channels/intercom.
 *
 * We reimplemented that package rather than depending on it (it pins
 * illuminate/* to ~9.0 and cannot install on Laravel 13). These tests pin the
 * public surface to the upstream behaviour so code written against the
 * package's documentation keeps working here.
 */
test('the package README example produces the documented payload', function () {
    $message = IntercomMessage::create('Hey User!')
        ->from('123')
        ->toUserId('321');

    // Key order matches upstream: the constructor sets body before defaulting
    // the message type, so `body` lands first.
    expect($message->toArray())->toBe([
        'body' => 'Hey User!',
        'message_type' => 'inapp',
        'from' => ['type' => 'admin', 'id' => '123'],
        'to' => ['type' => 'user', 'id' => '321'],
    ]);
});

test('messages default to inapp', function () {
    expect(IntercomMessage::create('hi')->toArray()['message_type'])->toBe('inapp');
    expect((new IntercomMessage)->toArray()['message_type'])->toBe('inapp');
});

test('body is omitted until set', function () {
    expect((new IntercomMessage)->toArray())->not->toHaveKey('body');
});

test('email and inapp flip the message type', function () {
    expect(IntercomMessage::create('x')->email()->toArray()['message_type'])->toBe('email');
    expect(IntercomMessage::create('x')->email()->inapp()->toArray()['message_type'])->toBe('inapp');
});

test('subject plain and personal set their fields', function () {
    $message = IntercomMessage::create('x')->email()->subject('Heads up')->plain();

    expect($message->toArray()['subject'])->toBe('Heads up');
    expect($message->toArray()['template'])->toBe('plain');
    expect($message->personal()->toArray()['template'])->toBe('personal');
});

test('every recipient helper builds the right to object', function () {
    expect(IntercomMessage::create('x')->toUserId('1')->toArray()['to'])
        ->toBe(['type' => 'user', 'id' => '1']);

    expect(IntercomMessage::create('x')->toUserEmail('a@b.test')->toArray()['to'])
        ->toBe(['type' => 'user', 'email' => 'a@b.test']);

    expect(IntercomMessage::create('x')->toContactId('9')->toArray()['to'])
        ->toBe(['type' => 'contact', 'id' => '9']);

    // dply extension: Intercom's `to.type = email`, which upstream omits.
    expect(IntercomMessage::create('x')->toEmail('a@b.test')->toArray()['to'])
        ->toBe(['type' => 'email', 'email' => 'a@b.test']);

    // The raw escape hatch upstream exposes.
    expect(IntercomMessage::create('x')->to(['type' => 'lead', 'id' => '7'])->toArray()['to'])
        ->toBe(['type' => 'lead', 'id' => '7']);
});

test('isValid requires body from and to', function () {
    expect(IntercomMessage::create('x')->isValid())->toBeFalse();
    expect(IntercomMessage::create('x')->from('1')->isValid())->toBeFalse();
    expect(IntercomMessage::create('x')->from('1')->toUserId('2')->isValid())->toBeTrue();
    expect((new IntercomMessage)->from('1')->toUserId('2')->isValid())->toBeFalse();
});

test('toIsGiven reports whether a recipient was named', function () {
    expect(IntercomMessage::create('x')->toIsGiven())->toBeFalse();
    expect(IntercomMessage::create('x')->toUserId('2')->toIsGiven())->toBeTrue();
});

test('credentials ride alongside the payload not inside it', function () {
    $message = IntercomMessage::create('x')->token('secret-token')->region('eu');

    expect($message->getToken())->toBe('secret-token');
    expect($message->getRegion())->toBe('eu');
    // An access token has no business in a request body.
    expect($message->toArray())->not->toHaveKey('token');
    expect($message->toArray())->not->toHaveKey('region');
});

test('createConversationWithoutContactReply is emitted for intercom', function () {
    expect(IntercomMessage::create('x')->createConversationWithoutContactReply()->toArray())
        ->toHaveKey('create_conversation_without_contact_reply', true);
});

test('error copy points at the fix, not at the code', function () {
    // Only codes Intercom actually documents are mapped; anything else falls
    // through to the default arm rather than inventing a meaning.
    expect(IntercomClient::describeError('action_forbidden'))->toContain('Write conversations');
    expect(IntercomClient::describeError('token_unauthorized'))->toContain('access token');
    expect(IntercomClient::describeError('admin_not_found'))->toContain('Teammates');
    // Intercom answers a missing recipient with a bare 404 and no error code.
    expect(IntercomClient::describeError('http_404'))->toContain('recipient');
    expect(IntercomClient::describeError('some_new_code'))->toContain('some_new_code');
});

test('unknown regions fall back to us rather than producing a bad host', function () {
    expect(IntercomClient::normalizeRegion('EU'))->toBe('eu');
    expect(IntercomClient::normalizeRegion('  au '))->toBe('au');
    expect(IntercomClient::normalizeRegion('mars'))->toBe('us');
    expect(IntercomClient::normalizeRegion(''))->toBe('us');
});
