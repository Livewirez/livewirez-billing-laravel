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
        Schema::create('billing_order_billing_product', function (Blueprint $table) {
            $table->unsignedBigInteger('billing_order_id');
            $table->unsignedBigInteger('billing_product_id');
            $table->unsignedBigInteger('quantity');
            $table->timestamps();
            
            $table->foreign('billing_order_id')->references('id')->on('billing_orders')->onDelete('cascade');
            $table->foreign('billing_product_id')->references('id')->on('billing_products')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_order_billing_product');
    }
};
