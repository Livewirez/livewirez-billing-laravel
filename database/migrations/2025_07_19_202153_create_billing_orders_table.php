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
        Schema::create('billing_orders', function (Blueprint $table) {
            $table->id();

            $table->timestamps();
            $table->uuid('billing_order_id')->unique();
            
            // Relationships
            $table->morphs('billable');
            $table->foreignId('billing_address_id')->nullable()->constrained('billable_addresses')->onDelete('set null');
            $table->foreignId('billing_order_shipping_address_id')->nullable()->constrained('billing_order_shipping_addresses')->onDelete('set null');
            $table->foreignId('billing_subscription_id')->nullable()->constrained('billing_subscriptions')->onDelete('set null');

            // Order details
            $table->string('order_number')->unique(); // e.g. "ORD-2025-0001"
            $table->string('status')->default('PENDING');
            $table->string('currency')->default(config('billing.default_currency', 'USD')); // ISO 4217
            $table->unsignedBigInteger('subtotal')->default(0); // before tax & shipping
            $table->unsignedBigInteger('discount')->default(0);
            $table->unsignedBigInteger('tax')->default(0);
            $table->unsignedBigInteger('shipping')->default(0);
            $table->unsignedBigInteger('total')->default(0);

            // Payment details
            $table->string('payment_status')->default('UNPAID');
            $table->string('payment_provider')->nullable(); // 'paypal', 'stripe', 'mpesa', etc.
            $table->string('sub_payment_provider')->nullable();
            $table->string('payment_provider_order_id')->nullable(); // external payment reference
            $table->string('payment_provider_checkout_id')->nullable(); // external payment reference
            $table->string('payment_provider_transaction_id')->nullable();

            // Metadata
            $table->text('metadata')->nullable(); // flexible data
            $table->timestamp('processed_at')->nullable();

            $table->index([
                'order_number',
                'billing_order_id', 
                'status',
                'payment_status', 
                'processed_at',
                'payment_provider'
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_orders');
    }
};
