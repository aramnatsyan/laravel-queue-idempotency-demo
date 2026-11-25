<?php

namespace Tests\Feature;

use App\Jobs\ChargeOrder;
use App\Models\Charge;
use App\Services\FakeGateway;
use App\Services\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ChargeOrderTest extends TestCase
{
    use RefreshDatabase;

    private FakeGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        config(['demo.backoff' => 0]);
        $this->gateway = new FakeGateway();
        $this->app->instance(PaymentGateway::class, $this->gateway);
    }

    private function runWorker(): void
    {
        $this->artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0])->assertSuccessful();
    }

    public function test_crash_after_the_gateway_charged_does_not_charge_twice_on_retry(): void
    {
        $this->gateway->loseResponseOnce = true; // gateway charges, then the response is lost
        ChargeOrder::dispatch(42, 1999);

        $this->runWorker(); // attempt 1 fails, attempt 2 reconciles via the idempotency key

        $this->assertSame(1, DB::table('gateway_ledger')->count());
        $this->assertSame(1, $this->gateway->calls, 'the retry must look up, not charge again');
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $charge = Charge::where('order_id', 42)->first();
        $this->assertSame('charged', $charge->status);
        $this->assertSame(2, $charge->attempts);
    }

    public function test_the_same_order_dispatched_twice_charges_once(): void
    {
        ChargeOrder::dispatch(7, 500);
        ChargeOrder::dispatch(7, 500);

        $this->runWorker();

        $this->assertSame(1, DB::table('gateway_ledger')->count());
        $this->assertSame(1, $this->gateway->calls);
        $this->assertSame(1, Charge::where('order_id', 7)->count());
        $this->assertSame('charged', Charge::where('order_id', 7)->value('status'));
    }

    public function test_a_job_that_keeps_failing_ends_in_failed_jobs_and_charges_nothing(): void
    {
        $this->gateway->alwaysFail = true;
        ChargeOrder::dispatch(9, 300);

        $this->runWorker();

        $this->assertSame(1, DB::table('failed_jobs')->count());
        $this->assertSame(0, DB::table('gateway_ledger')->count());
        $this->assertSame('charging', Charge::where('order_id', 9)->value('status'));
        $this->assertSame(3, $this->gateway->calls);
    }

    public function test_a_failed_job_can_be_retried_after_the_gateway_recovers(): void
    {
        $this->gateway->alwaysFail = true;
        ChargeOrder::dispatch(11, 800);
        $this->runWorker();
        $this->assertSame(1, DB::table('failed_jobs')->count());

        $this->gateway->alwaysFail = false;
        $this->artisan('queue:retry', ['id' => ['all']])->assertSuccessful();
        $this->runWorker();

        $this->assertSame(1, DB::table('gateway_ledger')->count());
        $this->assertSame('charged', Charge::where('order_id', 11)->value('status'));
    }
}
