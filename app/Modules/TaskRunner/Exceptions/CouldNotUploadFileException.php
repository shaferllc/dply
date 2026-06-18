<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Exceptions;

use App\Modules\TaskRunner\ProcessOutput;
use Exception;

class CouldNotUploadFileException extends Exception
{
    public function __construct(
        public readonly ProcessOutput $output,
        string $message = 'Could not upload file'
    ) {
        parent::__construct($message);
    }

    public static function fromProcessOutput(ProcessOutput $output): self
    {
        $snippet = trim($output->getBuffer());
        if (strlen($snippet) > 800) {
            $snippet = substr($snippet, 0, 800).'…';
        }
        $message = 'Could not upload file';
        if ($snippet !== '') {
            $message .= ': '.$snippet;
        }

        return new self($output, $message);
    }
}
