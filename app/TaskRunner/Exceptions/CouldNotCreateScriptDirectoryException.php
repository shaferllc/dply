<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Exceptions;

use App\Modules\TaskRunner\ProcessOutput;
use Exception;

class CouldNotCreateScriptDirectoryException extends Exception
{
    public function __construct(
        string $message,
        public readonly ProcessOutput $output
    ) {
        parent::__construct($message);
    }

    public static function fromProcessOutput(ProcessOutput $output): self
    {
        $snippet = trim($output->getBuffer());
        if (strlen($snippet) > 800) {
            $snippet = substr($snippet, 0, 800).'…';
        }
        $message = 'Could not create script directory';
        if ($snippet !== '') {
            $message .= ': '.$snippet;
        }

        return new self($message, $output);
    }
}
