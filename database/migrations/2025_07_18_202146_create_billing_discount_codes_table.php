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
        Schema::create('billing_discount_codes', function (Blueprint $table) {
            $table->id(); // BIGINT UNSIGNED auto-increment primary key
            $table->timestamps(); // created_at and updated_at with CURRENT_TIMESTAMP defaults
            $table->uuid('billing_discount_code_id')->unique();

            $table->string('code', 100)->unique();
            $table->string('name');
            $table->enum('billing_type', ['one-time', 'subscription'])->default('one-time');
            $table->enum('type', ['percentage', 'fixed_amount', 'fixed'])->default('percentage');
            $table->integer('value')->default(0);
            $table->string('currency', 10)->default(config('billing.default_currency', 'USD'));

            $table->integer('max_uses')->nullable();
            $table->integer('used_count')->default(0);
            $table->integer('max_uses_per_customer')->default(1);

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->integer('extends_trial_days')->default(0);
            $table->boolean('is_active')->default(true);
            $table->text('metadata')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_discount_codes');
    }
};
