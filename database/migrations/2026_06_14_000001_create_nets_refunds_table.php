<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Udviklr\CashierNets\Refund;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('nets_refunds', function (Blueprint $table): void {
            $table->id();
            // Refunds may be observed via webhook before (or without) a local
            // charge transaction, so the billable is not always resolvable.
            $table->nullableMorphs('billable');
            $table->foreignId('nets_transaction_id')->nullable()->index();
            $table->string('nets_charge_id')->nullable()->index();
            $table->string('nets_payment_id')->nullable()->index();
            // Unique so redelivered webhooks reconcile the existing row instead
            // of inserting duplicates that would inflate the refunded totals.
            $table->string('nets_refund_id')->nullable()->unique();
            $table->string('idempotency_key')->nullable()->unique();
            $table->string('status')->default(Refund::STATUS_PENDING);
            $table->unsignedInteger('amount')->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('failure_code')->nullable();
            $table->text('failure_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nets_refunds');
    }
};
