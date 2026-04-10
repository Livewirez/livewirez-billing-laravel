<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewirez\Billing\Enums\{ActionType, EntityType};

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('billing_payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->uuid('billing_payment_transaction_id')->unique();
            $table->morphs('billable');

            // Can be linked to a payment OR subscription
            $table->foreignId('billing_subscription_id')->nullable()->constrained('billing_subscriptions')->onDelete('set null');
            $table->foreignId('billing_order_id')->nullable()->constrained('billing_orders')->onDelete('set null');
            $table->string('action_type');
            $table->string('type');
            $table->string('status'); // pending, paid, failed, refunded
            $table->bigInteger('total_amount'); // store as scaled integer
            $table->bigInteger('earnings')->default(0); // store as scaled integer
            $table->bigInteger('subtotal')->default(0); // store as scaled integer
            $table->bigInteger('discount')->default(0); // store as scaled integer
            $table->bigInteger('tax')->default(0); // store as scaled integer
            $table->bigInteger('provider_fee')->default(0); // store as scaled integer
            $table->unsignedTinyInteger('scale')->default(2); // scale of the amount
            $table->string('currency', 10)->default(config('billing.default_currency', 'USD')); // e.g., USD, BTC, ETH, USDT
            $table->string('payment_provider'); // stripe, paypal, etc.
            $table->string('payment_provider_checkout_id')->nullable();
            $table->string('payment_provider_subscription_id')->nullable();
            $table->string('payment_provider_transaction_id')->nullable();
            $table->string('payment_provider_invoice_id')->nullable();
            $table->string('payment_provider_invoice_number')->nullable();
            $table->string('sub_payment_provider')->nullable();
            $table->text('metadata')->nullable();

            $table->timestamp('transacted_at')->nullable();

            $table->index(['billing_payment_transaction_id', 'status', 'transacted_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_payment_transactions');
    }
};
