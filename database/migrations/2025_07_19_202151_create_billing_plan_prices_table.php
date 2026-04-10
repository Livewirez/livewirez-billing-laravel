<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Livewirez\Billing\Enums\CurrencyType;
use Illuminate\Database\Migrations\Migration;
use Livewirez\Billing\Enums\SubscriptionInterval;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('billing_plan_prices', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->foreignId('billing_plan_id')->constrained()->onDelete('cascade');
            $table->foreignId('billing_discount_code_id')->nullable()->constrained('billing_discount_codes')->onDelete('set null');

            $table->uuid('billing_plan_price_id')->unique(); 
            $table->string('interval')->default(SubscriptionInterval::MONTHLY->value);
            $table->integer('billing_interval_count')->default(1);
            $table->integer('custom_interval_count')->nullable(); // e.g., every 3 years

            $table->bigInteger('amount'); // Always store as integer
            $table->unsignedTinyInteger('scale')->default(2); // Indicates decimal places
            $table->string('currency', 10)->default(config('billing.default_currency', 'USD')); // USD, BTC, ETH, USDT, etc.
            $table->bigInteger('discount')->default(0);
            $table->unsignedTinyInteger('discount_scale')->default(2);
            $table->bigInteger('tax')->default(0); // scaled tax amount
            $table->unsignedTinyInteger('tax_scale')->default(2); // decimal places
            $table->string('tax_type')->nullable(); // numeric or percent
            $table->string('tax_model')->default('exclude');

        });

        /**
         * 
         *  | Currency | Input Value          | Scale | Stored Amount |
         *  | -------- | -------------------- | ----- | ------------- |
         *  | USD      | 12.34                | 2     | 1234          |
         *  | BTC      | 0.00005678           | 8     | 5678          |
         *  | ETH      | 0.000000000123456789 | 18    | 123456789     |

         *
         * 
         */
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_plan_prices');
    }
};
