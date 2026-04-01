<?php

namespace Tests\Unit\Services;

use App\Services\Servers\ServerProvisionSshKeyMaterial;
use Tests\TestCase;

class ServerProvisionSshKeyMaterialTest extends TestCase
{
    public function test_generate_returns_distinct_recovery_and_operational_keys(): void
    {
        $material = app(ServerProvisionSshKeyMaterial::class)->generate();

        $this->assertIsString($material['recovery_private_key']);
        $this->assertIsString($material['operational_private_key']);
        $this->assertIsString($material['recovery_public_key']);
        $this->assertIsString($material['operational_public_key']);
        $this->assertNotSame($material['recovery_private_key'], $material['operational_private_key']);
        $this->assertNotSame($material['recovery_public_key'], $material['operational_public_key']);
        $this->assertStringContainsString('BEGIN OPENSSH PRIVATE KEY', $material['recovery_private_key']);
        $this->assertStringContainsString('BEGIN OPENSSH PRIVATE KEY', $material['operational_private_key']);
        $this->assertStringStartsWith('ssh-rsa ', $material['recovery_public_key']);
        $this->assertStringStartsWith('ssh-rsa ', $material['operational_public_key']);
    }
}
