<?php

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Livewirez\Billing\Enums\ProductType;
use Illuminate\Database\Migrations\Migration;
use Livewirez\Billing\Enums\ProductCategory;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('billing_products', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->foreignId('billing_discount_code_id')->nullable()->constrained('billing_discount_codes')->onDelete('set null');
            $table->string('name');
            $table->string('description', 1000);
            $table->bigInteger('price');
            $table->unsignedTinyInteger('scale')->default(2);
            $table->string('currency', 10)->default(config('billing.default_currency', 'USD'));
            $table->string('url')->nullable();
            $table->string('thumbnail')->nullable();
            $table->string('colour')->nullable();
            $table->string('sku')->nullable();
            $table->text('images')->nullable();
            $table->string('product_type')->default(ProductType::PHYSICAL);
            $table->string('product_category')->default(ProductCategory::PHYSICAL_GOODS);
            $table->uuid('billing_product_id')->unique()->index('product_id_index');
            $table->bigInteger('tax')->default(0); 
            $table->string('tax_type')->nullable(); // numeric or percent
            $table->string('tax_model')->default('exclude');
            $table->bigInteger('discount')->default(0); // Percentage discount
            $table->bigInteger('discount_percentage')->default(0); // Shipping
            $table->bigInteger('shipping')->default(0); // Shipping
            $table->bigInteger('shipping_discount')->default(0); // Shipping
            $table->bigInteger('handling')->default(0); // Shipping
            $table->bigInteger('insurance')->default(0); // Shipping
            $table->unsignedTinyInteger('modifier_scale')->default(2); 
            $table->timestamp('discount_expires_at')->nullable(); // Expiration date
            $table->boolean('is_active')->default(true);
            $table->float('weight')->nullable();
            $table->string('brand')->nullable();
            $table->integer('stock')->default(0);
            $table->text('metadata')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_products');
    }
};
