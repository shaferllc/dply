<?php

declare(strict_types=1);

namespace Tests\Unit\Support\DeployLogSanitizerTest;

use App\Support\DeployLogRedactor;
use App\Support\DeployLogSanitizer;

test('strips the colour codes composer paints dependency output with', function () {
    // What Composer actually emits while resolving packages. Rendered in a
    // browser the ESC byte vanishes and the rest shows as literal "[39m".
    $raw = "  \e[32;1mDONE\e[39;22m\n  nunomaduro/termwind \e[90m.\e[39m\e[90m.\e[39m\e[90m.\e[39m \e[32;1mDONE\e[39;22m";

    $clean = DeployLogSanitizer::sanitize($raw);

    expect($clean)->toBe("  DONE\n  nunomaduro/termwind ... DONE");
    expect($clean)->not->toContain('[39m');
    expect($clean)->not->toContain("\e");
});

test('keeps only the final paint of a carriage-return progress animation', function () {
    $raw = "Downloading: 0%\rDownloading: 50%\rDownloading: 100%\nDone";

    expect(DeployLogSanitizer::sanitize($raw))->toBe("Downloading: 100%\nDone");
});

test('leaves crlf line endings as real newlines', function () {
    expect(DeployLogSanitizer::sanitize("first\r\nsecond"))->toBe("first\nsecond");
});

test('preserves ordinary text, tabs, and bracketed prefixes', function () {
    // Square brackets are not inherently escape syntax — a log line that just
    // happens to contain them has to survive intact.
    $raw = "[dply] composer not found\n\tRetrying [attempt 2]";

    expect(DeployLogSanitizer::sanitize($raw))->toBe($raw);
});

test('strips osc sequences and stray control bytes', function () {
    // \x07 is BEL — the OSC terminator. PHP double quotes have no \a escape.
    $raw = "\e]0;window title\x07Building\x07 the artifact";

    expect(DeployLogSanitizer::sanitize($raw))->toBe('Building the artifact');
});

test('strips an osc sequence terminated by the string terminator', function () {
    $raw = "\e]8;;https://example.com\e\\link text\e]8;;\e\\ after";

    expect(DeployLogSanitizer::sanitize($raw))->toBe('link text after');
});

test('collapses the blank-line hole a cleared progress bar leaves behind', function () {
    expect(DeployLogSanitizer::sanitize("start\n\n\n\n\nend"))->toBe("start\n\nend");
});

test('is idempotent', function () {
    $raw = "\e[32;1mDONE\e[39;22m\rfinal\nnext";
    $once = DeployLogSanitizer::sanitize($raw);

    expect(DeployLogSanitizer::sanitize($once))->toBe($once);
});

test('empty input stays empty', function () {
    expect(DeployLogSanitizer::sanitize(''))->toBe('');
});

test('redactor catches a secret that colour codes had split apart', function () {
    // The escape sequence sat between the key and the value, so the redaction
    // pattern could not see "password=hunter2" as one token until the escapes
    // came off. This is why sanitizing runs first.
    $raw = "password=\e[0mhunter2";

    $redacted = DeployLogRedactor::redact($raw);

    expect($redacted)->not->toContain('hunter2');
    expect($redacted)->toContain('[redacted]');
});

test('redactor still removes plain secrets and terminal noise together', function () {
    $raw = "\e[90mfetching\e[39m\nGITHUB_TOKEN=ghp_realsecretvalue\n";

    $redacted = DeployLogRedactor::redact($raw);

    expect($redacted)->toContain('fetching');
    expect($redacted)->not->toContain('ghp_realsecretvalue');
    expect($redacted)->not->toContain("\e");
});
