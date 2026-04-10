<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Livewirez\Billing\Enums\CurrencyType;
use Illuminate\Database\Migrations\Migration;
use Livewirez\Billing\Enums\SubscriptionInterval;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('billing_plan_price_payment_provider_information', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->foreignId('billing_plan_id')->constrained('billing_plans')->onDelete('cascade');
            $table->foreignId('billing_plan_price_id')->nullable()->constrained('billing_plan_prices')->onDelete('cascade');

            $table->string('payment_provider'); // stripe, paypal, card
            $table->string('payment_provider_plan_id')->nullable();
            $table->string('payment_provider_plan_price_id')->nullable();
            $table->string('status')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('metadata')->nullable();

            $table->index(['payment_provider_plan_price_id', 'payment_provider']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_plan_price_payment_provider_information');
    }
};
