<?php

namespace App\Jobs;

use App\Models\Charge;
use App\Services\PaymentGateway;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

class ChargeOrder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public int $orderId, public int $amountCents)
    {
    }

    public function backoff(): int
    {
        return (int) config('demo.backoff', 10);
    }

    public function handle(PaymentGateway $gateway): void
    {
        $charge = $this->claim();
        if ($charge === null) {
            return; // already charged: a duplicate or a re-run after success
        }

        // After a crash the intent row says "charging": ask the gateway before charging again.
        $ref = $gateway->findByKey($charge->idempotency_key)
            ?? $gateway->charge($charge->idempotency_key, $charge->amount_cents);

        Charge::whereKey($charge->id)->update(['status' => 'charged', 'gateway_ref' => $ref]);
    }

    private function claim(): ?Charge
    {
        try {
            Charge::create([
                'order_id' => $this->orderId,
                'idempotency_key' => 'order-' . $this->orderId,
                'amount_cents' => $this->amountCents,
                'status' => 'pending',
            ]);
        } catch (UniqueConstraintViolationException) {
            // another job already created the intent row
        }

        return DB::transaction(function () {
            $charge = Charge::where('order_id', $this->orderId)->lockForUpdate()->firstOrFail();
            if ($charge->status === 'charged') {
                return null;
            }
            $charge->update(['status' => 'charging', 'attempts' => $charge->attempts + 1]);

            return $charge;
        });
    }
}
