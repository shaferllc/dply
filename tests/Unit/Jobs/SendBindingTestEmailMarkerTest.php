<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\SendBindingTestEmailMarkerTest;

use App\Jobs\SendBindingTestEmailJob;
use ReflectionMethod;

function marker(string $output, string $key): string
{
    $job = new SendBindingTestEmailJob('c', 's', 'b', 'tj@example.com');
    $method = new ReflectionMethod($job, 'marker');
    $method->setAccessible(true);

    return $method->invoke($job, $output, $key);
}

test('the provider message id is read out of the runner output', function () {
    $out = "DPLY_MAIL_ID=<abc123@wisp.dply.io>\nDPLY_MAIL_TRANSPORT=Illuminate\\Mail\\Transport\\CloudflareTransport\nDPLY_MAIL_OK";

    expect(marker($out, 'DPLY_MAIL_ID'))->toBe('<abc123@wisp.dply.io>')
        ->and(marker($out, 'DPLY_MAIL_TRANSPORT'))->toBe('Illuminate\\Mail\\Transport\\CloudflareTransport');
});

test('a marker the provider did not supply reads as empty, not as another marker', function () {
    // A transport that returns no SentMessage prints no ID line at all; the
    // lookup must not fall through to the next marker's value.
    $out = "DPLY_MAIL_TRANSPORT=Symfony\\Component\\Mailer\\Transport\\SendmailTransport\nDPLY_MAIL_OK";

    expect(marker($out, 'DPLY_MAIL_ID'))->toBe('')
        ->and(marker('', 'DPLY_MAIL_ID'))->toBe('');
});
