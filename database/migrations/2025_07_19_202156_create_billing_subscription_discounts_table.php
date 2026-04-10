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
        Schema::create('billing_subscription_discounts', function (Blueprint $table) {
            $table->id(); // BIGINT UNSIGNED auto-increment primary key
            $table->timestamps(); // Adds created_at and updated_at
            $table->uuid('billing_subscription_discount_id')->unique();

            // Foreign keys to subscriptions and discount_codes
            $table->foreignId('billing_subscription_id')
                  ->constrained('billing_subscriptions')
                  ->cascadeOnDelete();

            $table->foreignId('billing_discount_code_id')
                  ->constrained('billing_discount_codes');

            // Discount tracking columns
            $table->integer('discount_amount');
            $table->timestamp('applied_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_subscription_discounts');
    }
};
