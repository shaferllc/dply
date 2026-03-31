<?php

namespace Tests\Unit\Services;

use App\Services\Servers\ServerDatabaseDumpOutputValidator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServerDatabaseDumpOutputValidatorTest extends TestCase
{
    #[Test]
    public function mysql_success_dump_is_not_failed(): void
    {
        $sql = "-- MySQL dump\nCREATE TABLE t (id int);\n";
        $this->assertFalse(ServerDatabaseDumpOutputValidator::looksLikeFailedDump('mysql', $sql));
    }

    #[Test]
    public function mysql_detects_mysqldump_prefix_line(): void
    {
        $out = "mysqldump: Got error: 1045: Access denied\n";
        $this->assertTrue(ServerDatabaseDumpOutputValidator::looksLikeFailedDump('mysql', $out));
    }

    #[Test]
    public function mysql_detects_access_denied(): void
    {
        $this->assertTrue(ServerDatabaseDumpOutputValidator::looksLikeFailedDump(
            'mysql',
            'ERROR 1045 (28000): Access denied for user'
        ));
    }

    #[Test]
    public function postgres_detects_pg_dump_error_line(): void
    {
        $out = "pg_dump: error: connection to server failed\n";
        $this->assertTrue(ServerDatabaseDumpOutputValidator::looksLikeFailedDump('postgres', $out));
    }

    #[Test]
    public function postgres_success_dump_is_not_failed(): void
    {
        $sql = "--\n-- PostgreSQL database dump\n--\n\nSET statement_timeout = 0;\n";
        $this->assertFalse(ServerDatabaseDumpOutputValidator::looksLikeFailedDump('postgres', $sql));
    }
}
