<?php


namespace Tests\Unit\Services\ServerDatabaseDumpOutputValidatorTest;
use App\Services\Servers\ServerDatabaseDumpOutputValidator;
use PHPUnit\Framework\Attributes\Test;

test('mysql success dump is not failed', function () {
    $sql = "-- MySQL dump\nCREATE TABLE t (id int);\n";
    expect(ServerDatabaseDumpOutputValidator::looksLikeFailedDump('mysql', $sql))->toBeFalse();
});

test('mysql detects mysqldump prefix line', function () {
    $out = "mysqldump: Got error: 1045: Access denied\n";
    expect(ServerDatabaseDumpOutputValidator::looksLikeFailedDump('mysql', $out))->toBeTrue();
});

test('mysql detects access denied', function () {
    expect(ServerDatabaseDumpOutputValidator::looksLikeFailedDump(
        'mysql',
        'ERROR 1045 (28000): Access denied for user'
    ))->toBeTrue();
});

test('postgres detects pg dump error line', function () {
    $out = "pg_dump: error: connection to server failed\n";
    expect(ServerDatabaseDumpOutputValidator::looksLikeFailedDump('postgres', $out))->toBeTrue();
});

test('postgres success dump is not failed', function () {
    $sql = "--\n-- PostgreSQL database dump\n--\n\nSET statement_timeout = 0;\n";
    expect(ServerDatabaseDumpOutputValidator::looksLikeFailedDump('postgres', $sql))->toBeFalse();
});
