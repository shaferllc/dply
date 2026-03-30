<?php

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsPipeline;
use App\Actions\Decorators\PipelineDecorator;
use Illuminate\Pipeline\Pipeline;

class PipelineDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsPipeline::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        // Match closure patterns used by Pipeline::carry()
        // These patterns match the closures created inside Pipeline::carry()
        return $frame->matches(Pipeline::class, 'Illuminate\Pipeline\{closure}')
            || $frame->matches(Pipeline::class, '{closure:{closure:Illuminate\Pipeline\Pipeline::carry():184}:185}')
            || ($frame->instanceOf(Pipeline::class) && ($frame->function === null || str_contains($frame->function ?? '', 'closure')));
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        return app(PipelineDecorator::class, ['action' => $instance]);
    }
}
