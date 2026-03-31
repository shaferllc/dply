<?php

use App\Actions\Attributes\TransactionConnection;

describe('TransactionConnection', function () {
    it('can be instantiated', function () {
        expect(new TransactionConnection('mysql'))->toBeInstanceOf(TransactionConnection::class);
    });
});
