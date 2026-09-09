<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Sites\MailSendFailureHintTest;

use App\Support\Sites\MailSendFailureHint;

test('cloudflares opaque address error explains which addresses it means', function () {
    // The reported line, verbatim. The code says neither which address was
    // rejected nor why.
    $hint = MailSendFailureHint::for(
        'cloudflare',
        'Symfony\\Component\\Mailer\\Exception\\TransportException: email.sending.error.email.invalid',
    );

    expect($hint)->toContain('From address')
        ->and($hint)->toContain('MAIL_FROM_ADDRESS')
        ->and($hint)->toContain('Email Routing');
});

test('a missing transport class still names the composer package', function () {
    $hint = MailSendFailureHint::for('cloudflare', 'Class "Symfony\\Component\\HttpClient\\HttpClient" not found');

    expect($hint)->toContain('composer require symfony/http-client');
});

test('credential and verification failures get their own advice', function () {
    expect(MailSendFailureHint::for('postmark', 'HTTP 401 Unauthorized'))->toContain('credentials')
        ->and(MailSendFailureHint::for('mailgun', 'sending domain is not verified'))->toContain('Verify the sending domain')
        ->and(MailSendFailureHint::for('ses', 'HTTP 429 Too Many Requests'))->toContain('rate-limiting');
});

test('an unrecognised error produces no hint at all', function () {
    // The verbatim transport error is already on screen. Advising a composer
    // install for a rejected address — which is what this used to do — sends
    // someone to edit the wrong file.
    expect(MailSendFailureHint::for('cloudflare', 'Connection timed out after 30000 ms'))->toBeNull()
        ->and(MailSendFailureHint::for('smtp', 'Expected response code 250 but got 550'))->toBeNull();
});
