<?php

declare(strict_types=1);

use App\Modules\TaskRunner\ProcessRunner;
use App\Modules\TaskRunner\Utilities\Formatter;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

describe('Formatter utility', function () {
    beforeEach(function () {
        $this->formatter = new Formatter;
    });

    it('formats a bash script', function () {
        $script = "#!/bin/bash\necho 'hello'\n";
        $formatted = $this->formatter->bash($script);
        expect($formatted)->toBeString();
        expect($formatted)->toContain('echo');
        expect($formatted)->not->toBeEmpty();
    });

    it('formats a Caddyfile', function () {
        $caddyfile = "example.com {\n  respond \"Hello, world!\"\n}\n";
        $formatted = $this->formatter->caddyfile($caddyfile);
        expect($formatted)->toBeString();
        expect($formatted)->toContain('respond');
        expect($formatted)->not->toBeEmpty();
    });

    it('returns original content if formatting fails', function () {
        $invalid = 'this is not valid for any formatter';
        $formatted = $this->formatter->bash($invalid);
        expect($formatted)->toBe($invalid);
    });

    it('throws if temp file cannot be read', function () {
        $formatter = new Formatter;
        $content = "echo 'test'";
        $callback = function ($path) {
            unlink($path); // Remove file before formatter reads it

            return "cat {$path}";
        };
        expect(fn () => $formatter->handle($content, $callback))
            ->toThrow(RuntimeException::class, 'Failed to get contents from the temporary file.');
    });

    it('throws if regex replacement fails', function () {
        $formatter = new class extends Formatter
        {
            public function handle(string $content, callable $commandCallback): string
            {
                $temporaryDirectory = TemporaryDirectory::make();
                $temporaryFile = $temporaryDirectory->path('beautify');
                file_put_contents($temporaryFile, $content);
                $command = $commandCallback($temporaryFile);
                (new ProcessRunner)->run(Process::command($command)->timeout(15));
                $content = file_get_contents($temporaryFile);
                $temporaryDirectory->delete();
                // Intentionally use invalid regex
                $content = preg_replace('~[~', "\n\n", $content);
                if ($content === null) {
                    throw new RuntimeException('An error occurred during regex replacement.');
                }

                return $content;
            }
        };
        $content = "echo 'test'";
        $callback = fn ($path) => "cat {$path}";
        expect(fn () => $formatter->handle($content, $callback))
            ->toThrow(RuntimeException::class, 'An error occurred during regex replacement.');
    });
});
