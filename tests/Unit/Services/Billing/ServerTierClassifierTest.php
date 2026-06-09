<?php

namespace Tests\Unit\Services\Billing\ServerTierClassifierTest;

use App\Enums\ServerTier;
use App\Services\Billing\ServerTierClassifier;

beforeEach(function () {
    $this->classifier = new ServerTierClassifier;
});

test('nulls default to xs', function () {
    expect($this->classifier->classify(null, null))->toBe(ServerTier::XS);
});

test('one vcpu and two gb is xs', function () {
    expect($this->classifier->classify(1, 2048))->toBe(ServerTier::XS);
});

test('two vcpu four gb is s', function () {
    expect($this->classifier->classify(2, 4096))->toBe(ServerTier::S);
});

test('four vcpu eight gb is m', function () {
    expect($this->classifier->classify(4, 8192))->toBe(ServerTier::M);
});

test('eight vcpu sixteen gb is l', function () {
    expect($this->classifier->classify(8, 16384))->toBe(ServerTier::L);
});

test('above l is xl', function () {
    expect($this->classifier->classify(16, 32768))->toBe(ServerTier::XL);
});

test('takes the larger axis', function () {
    // Tiny CPU, big RAM — still pays the bigger-tier price.
    expect($this->classifier->classify(1, 16384))->toBe(ServerTier::L);

    // Huge CPU, modest RAM — same.
    expect($this->classifier->classify(32, 4096))->toBe(ServerTier::XL);
});

test('three vcpu falls into m bucket', function () {
    // 3 vCPU is unusual but exists (Hetzner CCX13, etc.); should land in M, not S.
    expect($this->classifier->classify(3, 4096))->toBe(ServerTier::M);
});

test('boundary just over l ram jumps to xl', function () {
    expect($this->classifier->classify(4, 16385))->toBe(ServerTier::XL);
});

test('unknown memory with known cpu uses cpu', function () {
    expect($this->classifier->classify(8, null))->toBe(ServerTier::L);
});

test('unknown cpu with known memory uses memory', function () {
    expect($this->classifier->classify(null, 8192))->toBe(ServerTier::M);
});
