<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Tests\Events;

use App\Modules\TaskRunner\Events\ParallelTaskProgress;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

uses(TestCase::class);

describe('ParallelTaskProgress Event', function () {
    beforeEach(function () {
        Event::fake();
    });

    it('creates event with required properties', function () {
        $executionId = 'parallel-exec-123';
        $current = 5;
        $total = 20;
        $percentage = 25.0;
        $message = 'Processing parallel tasks';
        $timestamp = now()->toISOString();

        $event = new ParallelTaskProgress($executionId, $current, $total, $percentage, $message, $timestamp);

        expect($event->executionId)->toBe($executionId);
        expect($event->current)->toBe($current);
        expect($event->total)->toBe($total);
        expect($event->percentage)->toBe($percentage);
        expect($event->message)->toBe($message);
        expect($event->timestamp)->toBe($timestamp);
    });

    it('calculates percentage integer correctly', function () {
        $event = new ParallelTaskProgress('exec-123', 7, 20, 35.0, 'Processing', now()->toISOString());

        expect($event->getPercentageInt())->toBe(35);
    });

    it('calculates percentage integer with rounding', function () {
        $event = new ParallelTaskProgress('exec-123', 7, 20, 35.7, 'Processing', now()->toISOString());

        expect($event->getPercentageInt())->toBe(36);
    });

    it('checks if execution is complete when current equals total', function () {
        $event = new ParallelTaskProgress('exec-123', 20, 20, 100.0, 'Complete', now()->toISOString());

        expect($event->isComplete())->toBeTrue();
    });

    it('checks if execution is complete when current exceeds total', function () {
        $event = new ParallelTaskProgress('exec-123', 25, 20, 125.0, 'Over complete', now()->toISOString());

        expect($event->isComplete())->toBeTrue();
    });

    it('checks if execution is not complete when current is less than total', function () {
        $event = new ParallelTaskProgress('exec-123', 15, 20, 75.0, 'In progress', now()->toISOString());

        expect($event->isComplete())->toBeFalse();
    });

    it('calculates remaining tasks correctly', function () {
        $event = new ParallelTaskProgress('exec-123', 7, 20, 35.0, 'Processing', now()->toISOString());

        expect($event->getRemaining())->toBe(13);
    });

    it('calculates remaining tasks when complete', function () {
        $event = new ParallelTaskProgress('exec-123', 20, 20, 100.0, 'Complete', now()->toISOString());

        expect($event->getRemaining())->toBe(0);
    });

    it('calculates remaining tasks when over complete', function () {
        $event = new ParallelTaskProgress('exec-123', 25, 20, 125.0, 'Over complete', now()->toISOString());

        expect($event->getRemaining())->toBe(0);
    });

    it('calculates progress ratio correctly', function () {
        $event = new ParallelTaskProgress('exec-123', 7, 20, 35.0, 'Processing', now()->toISOString());

        expect($event->getProgressRatio())->toBe(0.35);
    });

    it('calculates progress ratio when complete', function () {
        $event = new ParallelTaskProgress('exec-123', 20, 20, 100.0, 'Complete', now()->toISOString());

        expect($event->getProgressRatio())->toBe(1.0);
    });

    it('calculates progress ratio when over complete', function () {
        $event = new ParallelTaskProgress('exec-123', 25, 20, 125.0, 'Over complete', now()->toISOString());

        expect($event->getProgressRatio())->toBe(1.25);
    });

    it('handles zero total tasks for progress ratio', function () {
        $event = new ParallelTaskProgress('exec-123', 0, 0, 0.0, 'No tasks', now()->toISOString());

        expect($event->getProgressRatio())->toBe(0.0);
    });

    it('can be serialized and unserialized', function () {
        $executionId = 'parallel-exec-123';
        $current = 5;
        $total = 20;
        $percentage = 25.0;
        $message = 'Processing parallel tasks';
        $timestamp = now()->toISOString();

        $event = new ParallelTaskProgress($executionId, $current, $total, $percentage, $message, $timestamp);

        $serialized = serialize($event);
        $unserialized = unserialize($serialized);

        expect($unserialized)->toBeInstanceOf(ParallelTaskProgress::class);
        expect($unserialized->executionId)->toBe($executionId);
        expect($unserialized->current)->toBe($current);
        expect($unserialized->total)->toBe($total);
        expect($unserialized->percentage)->toBe($percentage);
        expect($unserialized->message)->toBe($message);
        expect($unserialized->timestamp)->toBe($timestamp);
    });

    it('can be dispatched', function () {
        $event = new ParallelTaskProgress('exec-123', 5, 20, 25.0, 'Processing', now()->toISOString());

        Event::dispatch($event);

        Event::assertDispatched(ParallelTaskProgress::class, function ($dispatchedEvent) use ($event) {
            return $dispatchedEvent->executionId === $event->executionId &&
                   $dispatchedEvent->current === $event->current &&
                   $dispatchedEvent->total === $event->total &&
                   $dispatchedEvent->percentage === $event->percentage &&
                   $dispatchedEvent->message === $event->message &&
                   $dispatchedEvent->timestamp === $event->timestamp;
        });
    });

    it('handles edge case with zero current and total', function () {
        $event = new ParallelTaskProgress('exec-123', 0, 0, 0.0, 'No tasks', now()->toISOString());

        expect($event->getPercentageInt())->toBe(0);
        expect($event->isComplete())->toBeTrue();
        expect($event->getRemaining())->toBe(0);
        expect($event->getProgressRatio())->toBe(0.0);
    });

    it('handles edge case with negative current', function () {
        $event = new ParallelTaskProgress('exec-123', -5, 20, -25.0, 'Negative progress', now()->toISOString());

        expect($event->getRemaining())->toBe(25); // 20 - (-5) = 25
        expect($event->isComplete())->toBeFalse();
        expect($event->getProgressRatio())->toBe(-0.25);
    });

    it('handles edge case with very large numbers', function () {
        $current = PHP_INT_MAX - 1000;
        $total = PHP_INT_MAX;
        $percentage = 99.9;

        $event = new ParallelTaskProgress('exec-123', $current, $total, $percentage, 'Large numbers', now()->toISOString());

        expect($event->getRemaining())->toBe(1000);
        expect($event->isComplete())->toBeFalse();
        expect($event->getProgressRatio())->toBe(($current / $total));
    });

    it('handles floating point precision in percentage calculations', function () {
        $event = new ParallelTaskProgress('exec-123', 1, 3, 33.333333, 'Precision test', now()->toISOString());

        expect($event->getProgressRatio())->toBe(1 / 3);
        expect($event->getPercentageInt())->toBe(33);
    });

    it('validates timestamp format', function () {
        $timestamp = now()->toISOString();
        $event = new ParallelTaskProgress('exec-123', 5, 20, 25.0, 'Processing', $timestamp);

        expect($event->timestamp)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/');
    });

    it('handles empty message', function () {
        $event = new ParallelTaskProgress('exec-123', 5, 20, 25.0, '', now()->toISOString());

        expect($event->message)->toBe('');
    });

    it('handles very long message', function () {
        $longMessage = str_repeat('A very long message that exceeds normal length ', 10);
        $event = new ParallelTaskProgress('exec-123', 5, 20, 25.0, $longMessage, now()->toISOString());

        expect($event->message)->toBe($longMessage);
    });

    it('handles special characters in message', function () {
        $specialMessage = 'Processing with special chars: !@#$%^&*()_+-=[]{}|;:,.<>?';
        $event = new ParallelTaskProgress('exec-123', 5, 20, 25.0, $specialMessage, now()->toISOString());

        expect($event->message)->toBe($specialMessage);
    });

    it('handles unicode characters in message', function () {
        $unicodeMessage = 'Processing with unicode: 你好世界 🌍 🚀';
        $event = new ParallelTaskProgress('exec-123', 5, 20, 25.0, $unicodeMessage, now()->toISOString());

        expect($event->message)->toBe($unicodeMessage);
    });
});
