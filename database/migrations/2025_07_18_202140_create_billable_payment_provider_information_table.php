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
        Schema::create('billable_payment_provider_information', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->morphs('billable');
            $table->string('payment_provider'); // stripe, paypal, card
            $table->string('payment_provider_user_id');

            // Billing data (non-sensitive PII)
            $table->foreignId('billing_address_id')->nullable()->constrained('billable_addresses')->onDelete('set null');
            
            $table->text('metadata')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billable_payment_provider_information');
    }
};
