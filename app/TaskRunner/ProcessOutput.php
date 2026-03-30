<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner;

use Illuminate\Contracts\Process\ProcessResult;

class ProcessOutput
{
    public string $buffer = '';

    public ?int $exitCode = null;

    public bool $timeout = false;

    /**
     * Create a new ProcessOutput instance.
     */
    public function __construct(string $buffer = '', ?int $exitCode = null, bool $timeout = false)
    {
        $this->buffer = $buffer;
        $this->exitCode = $exitCode;
        $this->timeout = $timeout;
    }

    public ?ProcessResult $illuminateResult = null;

    /**
     * @var callable|null
     */
    public $onOutput = null;

    /**
     * A PHP callback to run whenever there is some output available on STDOUT or STDERR.
     */
    public function onOutput(callable $callback): self
    {
        $this->onOutput = $callback;

        return $this;
    }

    /**
     * Appends the buffer to the output.
     *
     * @param  string  $buffer
     * @return void
     */
    public function __invoke(string $type, $buffer)
    {
        $this->buffer .= $buffer;

        if ($this->onOutput) {
            ($this->onOutput)($type, $buffer);
        }
    }

    /**
     * Helper to create a new instance.
     */
    public static function make(string $buffer = ''): self
    {
        $instance = new self;

        if ($buffer) {
            $instance('', $buffer);
        }

        return $instance;
    }

    public function getIlluminateResult(): ?ProcessResult
    {
        return $this->illuminateResult;
    }

    /**
     * Returns the buffer.
     */
    public function getBuffer(): string
    {
        return $this->buffer;
    }

    /**
     * Returns the buffer as an array of strings.
     *
     * @return array<string>
     */
    public function getLines(): array
    {
        return explode(PHP_EOL, $this->getBuffer());
    }

    /**
     * Returns the exit code.
     */
    public function getExitCode(): ?int
    {
        return $this->exitCode;
    }

    /**
     * Checks if the process was successful.
     */
    public function isSuccessful(): bool
    {
        return $this->getExitCode() === 0;
    }

    /**
     * Setter for the Illuminate ProcessResult instance.
     */
    public function setIlluminateResult(ProcessResult $result): self
    {
        $this->illuminateResult = $result;

        return $this;
    }

    /**
     * Setter for the exit code.
     */
    public function setExitCode(?int $exitCode = null): self
    {
        $this->exitCode = $exitCode;

        return $this;
    }

    /**
     * Checks if the process timed out.
     */
    public function isTimeout(): bool
    {
        return $this->timeout;
    }

    /**
     * Setter for the timeout.
     */
    public function setTimeout(bool $timeout): self
    {
        $this->timeout = $timeout;

        return $this;
    }
}
