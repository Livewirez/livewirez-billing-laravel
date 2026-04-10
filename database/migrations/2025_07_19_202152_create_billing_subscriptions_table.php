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
        Schema::create('billing_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->uuid('billing_subscription_id')->unique();
            $table->morphs('billable');
            $table->string('billable_key')->unique(); // {payer_type}:{payer_id}
            $table->foreignId('billing_plan_id')->nullable()->constrained('billing_plans')->onDelete('set null');
            $table->foreignId('billing_plan_price_id')->nullable()->constrained('billing_plan_prices')->onDelete('set null');
            $table->string('billing_plan_name')->nullable();

            $table->string('payment_provider'); // stripe, paypal // PayPal, Stripe, Wallet, Crypto, etc.
            $table->string('payment_provider_subscription_id')->nullable();
            $table->string('payment_provider_checkout_id')->nullable();
            $table->string('payment_provider_plan_id')->nullable();
            $table->string('sub_payment_provider')->nullable(); // stripe, paypal

            $table->string('interval'); // monthly, yearly
            $table->integer('custom_interval_count')->nullable();

            $table->timestamp('trial_starts_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable(); // null = ongoing
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('resumed_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('next_billing_at')->nullable();

            $table->boolean('is_active')->default(false);
            $table->string('status')->default('INACTIVE'); 
            // e.g. active, past_due, canceled, expired, trialing, paused

            $table->text('metadata')->nullable(); // provider_id, gateway refs, etc.
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_subscriptions');
    }
};
