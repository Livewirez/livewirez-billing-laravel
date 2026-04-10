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
        Schema::create('billing_transaction_data', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->foreignId('billing_payment_transaction_id')
            ->nullable()
            ->constrained('billing_payment_transactions')
            ->onDelete('set null');

            $table->string('transaction_ref')->index();
            $table->string('payment_provider_transaction_id')->index();
            $table->string('event')->nullable();
            $table->string('transaction_summary')->nullable();
            $table->string('status')->nullable();
            $table->string('receipt_number')->nullable();
            $table->string('resource_id')->index()->nullable();
            $table->string('payment_provider');
            $table->string('sub_payment_provider')->nullable();
            $table->text('payment_response')->nullable();
            $table->string('webhook_id')->nullable();
            $table->text('webhook_response')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_transaction_data');
    }
};
