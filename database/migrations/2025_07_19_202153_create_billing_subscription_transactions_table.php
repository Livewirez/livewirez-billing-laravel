<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('billing_subscription_transactions', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->uuid('billing_subscription_transaction_id')->unique();

            $table->foreignId('billing_subscription_id')->constrained('billing_subscriptions', indexName: 'subscription_fk')->onDelete('cascade');
            $table->foreignId('from_billing_plan_id')->nullable()->constrained('billing_plans', indexName: 'from_plan_sub_transaction_fk')->onDelete('set null');
            $table->foreignId('from_billing_plan_price_id')->nullable()->constrained('billing_plan_prices', indexName: 'from_plan_price_sub_transaction_fk')->onDelete('set null');

            $table->foreignId('billing_plan_id')->nullable()->constrained('billing_plans', indexName: 'plan_sub_transaction_fk')->onDelete('set null');
            $table->foreignId('billing_plan_price_id')->nullable()->constrained('billing_plan_prices', indexName: 'plan_price_sub_transaction_fk')->onDelete('set null');

            $table->string('billing_plan_name')->nullable();
            $table->string('transaction_ref')->nullable()->index();
            $table->string('type')->default('initial');

            $table->string('payment_provider'); // stripe, paypal // PayPal, Stripe, Wallet, Crypto, etc.
            $table->string('payment_provider_subscription_id')->nullable();
            $table->string('payment_provider_checkout_id')->nullable();
            $table->string('payment_provider_plan_id')->nullable();
            $table->string('sub_payment_provider')->nullable(); // stripe, paypal

            $table->bigInteger('amount'); // store as scaled integer
            $table->unsignedTinyInteger('scale')->default(2); // scale of the amount
            $table->string('currency', 10)->default(config('billing.default_currency', 'USD')); // e.g., USD, BTC, ETH, USDT
            $table->string('interval'); // monthly, yearly
            $table->integer('custom_interval_count')->nullable();
            $table->string('status')->default('PENDING'); // active, canceled, expired, past_due
            $table->string('payment_status')->default('PENDING');
            $table->string('payment_provider_status')->nullable();

            $table->timestamp('applied_at')->nullable();
            $table->timestamp('due_date')->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->text('metadata')->nullable(); // gateway invoice_id, etc.
            
            $table->string('resource_id')->index()->nullable();
            $table->text('payment_response')->nullable();
            $table->text('webhook_response')->nullable();

            $table->index(['status', 'payment_status', 'type', 'payment_provider'], 'subscrip_tx_morph_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_subscription_transactions');
    }
};
