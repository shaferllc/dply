<?php

declare(strict_types=1);

namespace App\Support\Debug;

use Throwable;

/**
 * Per-request summary of the throwable that produced a control-plane 500,
 * so the branded error page can offer a collapsed "Technical details" panel
 * for signed-in operators (without turning APP_DEBUG on for everyone).
 */
final class DebugExceptionDetail
{
    /** @var array{class: string, message: string, file: string, line: int}|null */
    private static ?array $current = null;

    public static function remember(Throwable $e): void
    {
        $root = $e;
        while ($root->getPrevious() instanceof Throwable) {
            $root = $root->getPrevious();
        }

        $message = trim($root->getMessage());
        if ($message === '') {
            $message = $root::class;
        }

        $file = $root->getFile();
        $base = base_path();
        if (str_starts_with($file, $base)) {
            $file = ltrim(substr($file, strlen($base)), DIRECTORY_SEPARATOR);
        }

        self::$current = [
            'class' => $root::class,
            'message' => mb_substr($message, 0, 2000),
            'file' => $file,
            'line' => $root->getLine(),
        ];
    }

    /**
     * @return array{class: string, message: string, file: string, line: int}|null
     */
    public static function current(): ?array
    {
        return self::$current;
    }

    /** Signed-in control-plane users (or local debug) may expand the detail. */
    public static function viewerMaySee(): bool
    {
        return (bool) config('app.debug') || auth()->check();
    }
}
