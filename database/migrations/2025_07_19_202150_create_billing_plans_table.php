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
        Schema::create('billing_plans', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->uuid('billing_plan_id')->unique(); // internal or external identifier
            $table->foreignId('billing_product_id')->nullable()->constrained('billing_products')->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('type')->default('recurring'); // recurring or one-time
            $table->integer('ranking')->default(0);
            $table->integer('trial_days')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('url')->nullable();
            $table->string('thumbnail')->nullable();
            $table->text('features')->nullable(); // optional features list
            $table->text('metadata')->nullable();

            $table->index(['billing_plan_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_plans');
    }
};
