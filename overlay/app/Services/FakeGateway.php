<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

// Stand-in for a payment provider; its ledger table is the "outside world" side effect.
class FakeGateway implements PaymentGateway
{
    public int $calls = 0;
    public bool $loseResponseOnce = false;
    public bool $alwaysFail = false;

    public function findByKey(string $key): ?string
    {
        return DB::table('gateway_ledger')->where('idempotency_key', $key)->value('ref');
    }

    public function charge(string $key, int $amountCents): string
    {
        $this->calls++;

        if ($this->alwaysFail) {
            throw new RuntimeException('gateway down');
        }

        DB::table('gateway_ledger')->insertOrIgnore([
            'idempotency_key' => $key,
            'ref' => 'ch_' . Str::random(10),
            'amount_cents' => $amountCents,
        ]);
        $ref = $this->findByKey($key);

        if ($this->loseResponseOnce) {
            $this->loseResponseOnce = false;
            throw new RuntimeException('timeout: response lost after the charge went through');
        }

        return $ref;
    }
}
