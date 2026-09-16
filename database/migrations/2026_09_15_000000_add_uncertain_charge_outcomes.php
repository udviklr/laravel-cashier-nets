<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nets_transactions', function (Blueprint $table): void {
            $table->timestamp('uncertain_at')->nullable();
            $table->json('frozen_order')->nullable();
            $table->unique('idempotency_key');
            $table->unique('nets_charge_id');
        });

        Schema::table('nets_webhook_events', function (Blueprint $table): void {
            $table->string('source')->default('webhook');
        });
    }

    public function down(): void
    {
        Schema::table('nets_webhook_events', function (Blueprint $table): void {
            $table->dropColumn('source');
        });

        Schema::table('nets_transactions', function (Blueprint $table): void {
            $table->dropUnique(['idempotency_key']);
            $table->dropUnique(['nets_charge_id']);
            $table->dropColumn(['uncertain_at', 'frozen_order']);
        });
    }
};
