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
        Schema::create('billing_product_payment_provider_information', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->foreignId('billing_product_id')->nullable()->constrained('billing_products')->onDelete('cascade');
            $table->string('payment_provider'); // stripe, paypal, card
            $table->string('payment_provider_product_id');
            $table->string('payment_provider_price_id')->nullable();
            $table->string('payment_provider_media_id')->nullable();
            $table->text('payment_provider_price_ids')->nullable();
            $table->text('payment_provider_media_ids')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('metadata')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_product_payment_provider_information');
    }
};
