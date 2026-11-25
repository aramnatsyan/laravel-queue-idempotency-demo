<?php

namespace App\Services;

interface PaymentGateway
{
    // Returns the gateway reference if a charge with this key already exists.
    public function findByKey(string $key): ?string;

    public function charge(string $key, int $amountCents): string;
}
