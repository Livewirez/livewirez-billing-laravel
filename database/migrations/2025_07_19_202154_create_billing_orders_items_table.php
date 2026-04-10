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
        Schema::create('billing_order_items', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->foreignId('billing_order_id')->constrained('billing_orders')->onDelete('cascade');
            $table->foreignId('billing_product_id')->constrained('billing_products')->onDelete('set null');
            $table->foreignId('billing_plan_id')->nullable()->constrained('billing_plans')->onDelete('set null');
            $table->foreignId('billing_plan_price_id')->nullable()->constrained('billing_plan_prices')->onDelete('set null');
            $table->uuid('billing_order_item_id')->unique();
            $table->string('name'); // snapshot of product name
            $table->unsignedBigInteger('price');
            $table->string('thumbnail')->nullable();
            $table->string('url')->nullable();
            $table->integer('quantity');

            $table->string('currency')->default(config('billing.default_currency', 'USD')); // ISO 4217
            $table->unsignedBigInteger('subtotal')->default(0); // before tax & shipping
            $table->unsignedBigInteger('discount')->default(0);
            $table->unsignedBigInteger('tax')->default(0);
            $table->unsignedBigInteger('shipping')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            $table->string('type')->default('one-time');

            $table->string('status')->default('PENDING');
            $table->string('payment_status')->default('UNPAID');
            $table->string('delivery_status')->default('AWAITING_PROCESSING');
            $table->string('fulfillment_status')->default('UNFULFILLED');
            $table->timestamp('shipped_at')->nullable();
            $table->string('tracking_number')->nullable();
            $table->string('carrier')->nullable(); // FedEx, DHL, etc.

            $table->text('options')->nullable(); // size, color, etc.
            $table->text('metadata')->nullable(); // flexible data
            $table->timestamp('processed_at')->nullable();

            $table->index([
                'billing_order_item_id', 
                'status',
                'payment_status', 
                'delivery_status',
                'fulfillment_status',
                'processed_at',
            ]);
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_order_items');
    }
};
