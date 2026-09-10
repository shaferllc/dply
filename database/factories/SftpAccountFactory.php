<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Server;
use App\Models\SftpAccount;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SftpAccount>
 */
class SftpAccountFactory extends Factory
{
    protected $model = SftpAccount::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $username = 'ftp'.Str::lower(Str::random(6));

        return [
            'server_id' => Server::factory(),
            'site_id' => null,
            'username' => $username,
            'home_path' => '/home/'.$username,
            'status' => SftpAccount::STATUS_ACTIVE,
            'provisioned_at' => now(),
        ];
    }
}
