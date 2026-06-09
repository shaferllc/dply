<?php

declare(strict_types=1);

use App\Support\Servers\AccessLogVisitorClassifier;

test('classifies nginx combined access log lines', function () {
    $human = '127.0.0.1 - - [30/May/2026:10:00:00 +0000] "GET / HTTP/1.1" 200 1234 "-" "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36"';
    $google = '127.0.0.1 - - [30/May/2026:10:00:01 +0000] "GET /robots.txt HTTP/1.1" 200 55 "-" "Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)"';
    $gpt = '127.0.0.1 - - [30/May/2026:10:00:02 +0000] "GET / HTTP/1.1" 403 0 "-" "Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.0; +https://openai.com/gptbot)"';

    expect(AccessLogVisitorClassifier::classifyLine($human))->toBe(AccessLogVisitorClassifier::BUCKET_HUMAN)
        ->and(AccessLogVisitorClassifier::classifyLine($google))->toBe(AccessLogVisitorClassifier::BUCKET_CRAWLER)
        ->and(AccessLogVisitorClassifier::classifyLine($gpt))->toBe(AccessLogVisitorClassifier::BUCKET_AI);
});

test('traffic filters hide and show expected buckets', function () {
    $human = '"GET /" 200 "-" "Mozilla/5.0 Chrome/120 Safari/537.36"';
    $bot = '"GET /" 200 "-" "curl/8.0"';
    $ai = '"GET /" 200 "-" "ClaudeBot/1.0"';

    expect(AccessLogVisitorClassifier::lineMatchesFilter($human, 'humans'))->toBeTrue()
        ->and(AccessLogVisitorClassifier::lineMatchesFilter($bot, 'humans'))->toBeFalse()
        ->and(AccessLogVisitorClassifier::lineMatchesFilter($bot, 'bots'))->toBeTrue()
        ->and(AccessLogVisitorClassifier::lineMatchesFilter($ai, 'ai'))->toBeTrue()
        ->and(AccessLogVisitorClassifier::lineMatchesFilter($ai, 'noise'))->toBeFalse()
        ->and(AccessLogVisitorClassifier::lineMatchesFilter($human, 'noise'))->toBeTrue();
});

test('detects access log sources', function () {
    expect(AccessLogVisitorClassifier::isAccessLogSource('nginx_access', [
        'type' => 'file',
        'path' => '/var/log/nginx/access.log',
        'label' => 'Nginx access log',
    ]))->toBeTrue()
        ->and(AccessLogVisitorClassifier::isAccessLogSource('nginx_error', [
            'type' => 'file',
            'path' => '/var/log/nginx/error.log',
            'label' => 'Nginx error log',
        ]))->toBeFalse();
});
