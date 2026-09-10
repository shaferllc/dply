<?php

declare(strict_types=1);

use App\Services\Servers\ServerFileBrowserAtomicWriter;

/** Run the writer's heredoc through real bash and return the bytes it wrote. */
function heredocWrites(string $content): string
{
    $writer = new class extends ServerFileBrowserAtomicWriter
    {
        public function script(string $content): string
        {
            return $this->heredoc($content);
        }
    };

    $file = tempnam(sys_get_temp_dir(), 'dply-heredoc');
    exec('bash -c '.escapeshellarg('tmp='.escapeshellarg($file)."\n".$writer->script($content)));
    $written = (string) file_get_contents($file);
    unlink($file);

    return $written;
}

test('heredoc writes the content byte for byte when it ends in a newline', function () {
    // Previously each save appended a blank line — after a closing tag that is output before headers.
    expect(heredocWrites("<?php\necho 1;\n?>\n"))->toBe("<?php\necho 1;\n?>\n");
});

test('heredoc can only add a final newline to content without one', function () {
    expect(heredocWrites('<?php echo 1;'))->toBe("<?php echo 1;\n");
});
