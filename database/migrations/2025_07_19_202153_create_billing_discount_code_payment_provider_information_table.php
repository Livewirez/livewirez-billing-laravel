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
        Schema::create('billing_discount_code_payment_provider_information', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->foreignId('billing_discount_code_id')
                  ->constrained('billing_discount_codes');
            $table->foreignId('billing_product_id')->nullable()->constrained('billing_products')->onDelete('cascade');
            $table->foreignId('billing_plan_price_id')->nullable()->constrained('billing_plan_prices')->onDelete('cascade');
            $table->string('payment_provider'); // stripe, paypal, card
            $table->string('payment_provider_discount_code_id');
            $table->string('code', 100)->nullable();
            $table->enum('type', ['percentage', 'fixed_amount', 'fixed'])->default('percentage');
            $table->text('metadata')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_discount_code_payment_provider_information');
    }
};
