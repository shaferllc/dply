<?php


namespace Tests\Unit\Services\ServerProvisionSshKeyMaterialTest;
use App\Services\Servers\ServerProvisionSshKeyMaterial;

test('generate returns distinct recovery and operational keys', function () {
    $material = app(ServerProvisionSshKeyMaterial::class)->generate();

    expect($material['recovery_private_key'])->toBeString();
    expect($material['operational_private_key'])->toBeString();
    expect($material['recovery_public_key'])->toBeString();
    expect($material['operational_public_key'])->toBeString();
    $this->assertNotSame($material['recovery_private_key'], $material['operational_private_key']);
    $this->assertNotSame($material['recovery_public_key'], $material['operational_public_key']);
    $this->assertStringContainsString('BEGIN OPENSSH PRIVATE KEY', $material['recovery_private_key']);
    $this->assertStringContainsString('BEGIN OPENSSH PRIVATE KEY', $material['operational_private_key']);
    expect($material['recovery_public_key'])->toStartWith('ssh-rsa ');
    expect($material['operational_public_key'])->toStartWith('ssh-rsa ');
});
