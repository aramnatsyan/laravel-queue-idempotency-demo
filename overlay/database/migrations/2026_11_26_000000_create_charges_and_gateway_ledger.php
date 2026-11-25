<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charges', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->unique();
            $table->string('idempotency_key')->unique();
            $table->unsignedInteger('amount_cents');
            $table->string('status'); // pending, charging, charged
            $table->string('gateway_ref')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();
        });

        Schema::create('gateway_ledger', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key')->unique();
            $table->string('ref');
            $table->unsignedInteger('amount_cents');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_ledger');
        Schema::dropIfExists('charges');
    }
};
