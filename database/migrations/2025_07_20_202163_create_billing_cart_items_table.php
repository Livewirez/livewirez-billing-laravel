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
        Schema::create('billing_cart_items', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('billing_cart_id')->constrained('billing_carts')->onDelete('cascade');
            $table->foreignId('billing_product_id')->constrained('billing_products')->onDelete('cascade');
            $table->bigInteger('quantity')->default(1);

            $table->unique(['billing_cart_id', 'billing_product_id'], 'unique_cart_product');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_cart_items');
    }
};
