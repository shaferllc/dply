<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Turn raw terminal output into the plain text a deploy log should show.
 *
 * Build tools write for a TTY: Composer, npm, and pip colour their output with
 * ANSI escape sequences and animate progress by rewriting the current line with
 * carriage returns. A browser renders none of that — the ESC byte is invisible,
 * so `ESC[39m` shows up as a literal `[39m`, and a progress bar that redrew 200
 * times arrives as 200 concatenated copies. The result is the wall of
 * `[39m [90m.` noise that buries the lines an operator actually wants.
 *
 * Setting NO_COLOR/TERM=dumb on the build env stops most of it at the source
 * (see ServerlessBuildHostTools::processEnv()); this is the backstop for tools
 * that colour anyway, and for output captured before that env applied.
 *
 * Pairs with {@see DeployLogRedactor} — that one removes secrets, this one
 * removes terminal control noise. Both run before a log is persisted.
 */
class DeployLogSanitizer
{
    /**
     * Strip terminal control sequences and collapse carriage-return redraws.
     */
    public static function sanitize(string $log): string
    {
        if ($log === '') {
            return '';
        }

        // OSC (window title, hyperlinks): ESC ] … BEL, or ESC ] … ESC \.
        // Matched before CSI so the terminating ESC \ isn't consumed piecemeal.
        $log = (string) preg_replace('/\e\][^\a\e]*(?:\a|\e\\\\)/u', '', $log);

        // CSI (colour, cursor moves, line erase): ESC [ params intermediates final.
        $log = (string) preg_replace('/\e\[[0-9;:?]*[ -\/]*[@-~]/u', '', $log);

        // Remaining two-character escapes (charset selection, ESC 7/8 cursor save).
        $log = (string) preg_replace('/\e[@-Z\\\\-_]/u', '', $log);

        // A progress animation redraws one line many times, separated by \r.
        // A terminal shows only the final paint, so keep the text after the last
        // carriage return — but leave \r\n line endings alone (handled first).
        $log = str_replace("\r\n", "\n", $log);
        $log = implode("\n", array_map(
            static function (string $line): string {
                $painted = strrchr($line, "\r");

                return $painted === false ? $line : substr($painted, 1);
            },
            explode("\n", $log),
        ));

        // Leftover C0 control characters (BEL, backspace, form feed, vertical
        // tab). Newline and tab are real formatting and stay.
        $log = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $log);

        // A cleared progress line leaves an empty line behind; collapse runs of
        // three or more blank lines so the log doesn't gain a hole where the
        // animation used to be.
        $log = (string) preg_replace("/\n{3,}/u", "\n\n", $log);

        return $log;
    }
}
