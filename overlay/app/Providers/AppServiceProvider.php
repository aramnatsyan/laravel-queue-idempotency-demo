<?php

namespace App\Providers;

use App\Services\FakeGateway;
use App\Services\PaymentGateway;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PaymentGateway::class, FakeGateway::class);
    }

    public function boot(): void
    {
    }
}
