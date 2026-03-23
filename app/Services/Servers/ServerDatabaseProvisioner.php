<?php

namespace App\Services\Servers;

use App\Models\ServerDatabase;
use App\Services\SshConnection;

class ServerDatabaseProvisioner
{
    public function createOnServer(ServerDatabase $db): string
    {
        $server = $db->server;
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        $name = $db->name;
        $user = $db->username;
        $pass = $db->password;
        $ssh = new SshConnection($server);

        if ($db->engine === 'postgres') {
            $userSql = "CREATE USER {$user} WITH PASSWORD '".str_replace("'", "''", $pass)."';";
            $out = $ssh->exec('sudo -u postgres psql -v ON_ERROR_STOP=0 -c '.escapeshellarg($userSql), 120);
            $dbSql = "CREATE DATABASE {$name} OWNER {$user};";

            return $out."\n".$ssh->exec('sudo -u postgres psql -v ON_ERROR_STOP=0 -c '.escapeshellarg($dbSql), 120);
        }

        $passSql = str_replace(['\\', "'"], ['\\\\', "\\'"], $pass);
        $sql =
            "CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; ".
            "CREATE USER IF NOT EXISTS '{$user}'@'localhost' IDENTIFIED BY '{$passSql}'; ".
            "GRANT ALL PRIVILEGES ON `{$name}`.* TO '{$user}'@'localhost'; FLUSH PRIVILEGES;";

        return $ssh->exec('mysql -u root -e '.escapeshellarg($sql).' 2>&1', 120);
    }
}
