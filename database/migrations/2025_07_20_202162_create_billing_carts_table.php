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
        Schema::create('billing_carts', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->uuid('billing_cart_id')->unique();
            $table->morphs('billable');

            $table->foreignId('billing_order_id')->nullable()->constrained('billing_orders')->onDelete('set null');

            $table->string('currency', 10)->default(config('billing.default_currency', 'USD'));
            $table->unsignedBigInteger('total')->default(0);
            $table->unsignedBigInteger('subtotal')->default(0); // before tax & shipping
            $table->bigInteger('tax')->default(0); 
            $table->bigInteger('discount')->default(0); // Percentage discount
            $table->bigInteger('shipping')->default(0); // Shipping
            $table->bigInteger('shipping_discount')->default(0); // Shipping
            $table->bigInteger('handling')->default(0); // Shipping
            $table->bigInteger('insurance')->default(0); // Shipping
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_carts');
    }
};
