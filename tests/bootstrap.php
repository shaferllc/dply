<?php

require __DIR__.'/../vendor/autoload.php';

use Illuminate\Database\Connection;
use Tests\Support\TestingPostgresConnection;

if ((getenv('APP_ENV') ?: $_ENV['APP_ENV'] ?? null) === 'testing') {
    Connection::resolverFor('pgsql', function ($connection, $database, $prefix, $config) {
        return new TestingPostgresConnection($connection, $database, $prefix, $config);
    });
}

$argv = $_SERVER['argv'] ?? [];
$isParatestWorker = in_array('--status-file', $argv, true);

if (! $isParatestWorker) {
    $runsParallel = in_array('--parallel', $argv, true);

    $requestsCoverageReport = false;

    foreach ($argv as $argument) {
        if (str_starts_with($argument, '--coverage') || $argument === '--min') {
            $requestsCoverageReport = true;

            break;
        }
    }

    if ($runsParallel && $requestsCoverageReport) {
        // Paratest merges worker coverage in the parent process; serializing the
        // merged object exceeds the default 1G worker limit from phpunit.xml.
        ini_set('memory_limit', '2G');
    }
}
