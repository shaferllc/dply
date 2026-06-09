<?php

namespace Tests\Support;

use Illuminate\Database\PostgresConnection;

class TestingPostgresConnection extends PostgresConnection
{
    public function getSchemaBuilder()
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }

        return new TestingPostgresBuilder($this);
    }
}
