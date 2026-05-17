<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Udviklr\CashierNets\Subscription;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('nets_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->morphs('billable');
            $table->string('type')->default(Subscription::DEFAULT_TYPE);
            $table->string('nets_payment_id')->nullable()->index();
            $table->string('nets_subscription_id')->nullable()->unique();
            $table->string('nets_unscheduled_subscription_id')->nullable()->unique();
            $table->string('status')->default(Subscription::STATUS_PENDING);
            $table->unsignedInteger('amount')->nullable();
            $table->char('currency', 3)->nullable();
            $table->unsignedInteger('interval_days')->nullable();
            $table->timestamp('next_charge_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('last_charged_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['billable_type', 'billable_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nets_subscriptions');
    }
};
