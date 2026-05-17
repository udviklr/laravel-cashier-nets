<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Udviklr\CashierNets\Transaction;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('nets_transactions', function (Blueprint $table): void {
            $table->id();
            $table->morphs('billable');
            $table->string('nets_payment_id')->nullable()->index();
            $table->string('nets_charge_id')->nullable()->index();
            $table->string('idempotency_key')->nullable()->index();
            $table->string('nets_subscription_id')->nullable()->index();
            $table->string('nets_unscheduled_subscription_id')->nullable()->index();
            $table->string('status')->default(Transaction::STATUS_PENDING);
            $table->unsignedInteger('amount')->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('failure_code')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamp('billed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nets_transactions');
    }
};
