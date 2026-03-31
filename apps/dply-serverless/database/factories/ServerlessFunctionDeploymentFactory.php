<?php

namespace Database\Factories;

use App\Models\ServerlessFunctionDeployment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServerlessFunctionDeployment>
 */
class ServerlessFunctionDeploymentFactory extends Factory
{
    protected $model = ServerlessFunctionDeployment::class;

    public function definition(): array
    {
        return [
            'function_name' => 'test-fn',
            'runtime' => 'provided.al2023',
            'artifact_path' => '/dev/null',
            'status' => ServerlessFunctionDeployment::STATUS_QUEUED,
            'trigger' => ServerlessFunctionDeployment::TRIGGER_API,
            'idempotency_key' => null,
            'provisioner_output' => null,
            'revision_id' => null,
            'error_message' => null,
        ];
    }
}
