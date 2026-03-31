<?php

namespace App\Actions\Servers;

use App\Models\ServerProvisionRun;

class UpsertServerProvisionArtifact
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function handle(
        ServerProvisionRun $run,
        string $type,
        string $label,
        ?string $content = null,
        array $metadata = [],
        ?string $key = null,
    ): void {
        $run->artifacts()->updateOrCreate(
            [
                'type' => $type,
                'key' => $key,
            ],
            [
                'label' => $label,
                'content' => $content,
                'metadata' => $metadata,
            ],
        );
    }
}
