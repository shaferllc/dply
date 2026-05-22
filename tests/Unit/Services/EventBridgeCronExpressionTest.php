<?php

declare(strict_types=1);

namespace Tests\Unit\Services\EventBridgeCronExpressionTest;
use InvalidArgumentException;

use App\Services\Serverless\Aws\EventBridgeCronExpression;
test('an every day schedule gets a question mark day of week', function () {
    // Both day fields wildcard → EventBridge needs `?` in one of them.
    expect(EventBridgeCronExpression::fromStandardCron('*/5 * * * *'))->toBe('cron(*/5 * * * ? *)');
});
test('a day of month schedule keeps the day and blanks day of week', function () {
    expect(EventBridgeCronExpression::fromStandardCron('0 0 1 * *'))->toBe('cron(0 0 1 * ? *)');
});
test('a day of week schedule shifts to eventbridge numbering', function () {
    // Standard Monday is 1; EventBridge Monday is 2 (1 = Sunday there).
    expect(EventBridgeCronExpression::fromStandardCron('0 9 * * 1'))->toBe('cron(0 9 ? * 2 *)');
});
test('standard sunday zero and seven both map to eventbridge one', function () {
    expect(EventBridgeCronExpression::fromStandardCron('0 12 * * 0'))->toBe('cron(0 12 ? * 1 *)');
    expect(EventBridgeCronExpression::fromStandardCron('0 12 * * 7'))->toBe('cron(0 12 ? * 1 *)');
});
test('a day of week list shifts every token', function () {
    // Mon,Wed,Fri (1,3,5) → 2,4,6 in EventBridge numbering.
    expect(EventBridgeCronExpression::fromStandardCron('0 8 * * 1,3,5'))->toBe('cron(0 8 ? * 2,4,6 *)');
});
test('it rejects specifying both day of month and day of week', function () {
    $this->expectException(InvalidArgumentException::class);
    EventBridgeCronExpression::fromStandardCron('0 0 1 * 1');
});
test('it rejects a non five field expression', function () {
    $this->expectException(InvalidArgumentException::class);
    EventBridgeCronExpression::fromStandardCron('* * * *');
});
