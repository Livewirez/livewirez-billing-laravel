
<?php

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;


return new class extends Migration {
    public function up(): void {
        Schema::create('billable_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->morphs('billable');
            $table->foreignId('billable_payment_provider_information_id')->nullable()->constrained('billable_payment_provider_information')->onDelete('cascade');
            $table->uuid('billable_payment_method_id')->unique(); 
            $table->string('payment_provider'); // stripe, paypal, card
            $table->string('payment_provider_user_id')->nullable();
            $table->string('sub_payment_provider')->nullable(); // stripe, paypal, card
            $table->string('billable_user_key')->nullable()->unique();

            // Provider linkage (Stripe, Flutterwave, MPGS, CyberSource, etc.)    // cus_xxx
            $table->string('payment_provider_method_id')->nullable(); // pm_xxx / token
            $table->text('token')->nullable()->index();           // generic token / vault ref

            // Non-sensitive card metadata (OK to store)
            $table->string('brand', 30)->nullable();                     // 'visa','mastercard'
            $table->unsignedTinyInteger('exp_month')->nullable();
            $table->unsignedSmallInteger('exp_year')->nullable();
            $table->string('last4', 4)->nullable();
            $table->string('funding', 16)->nullable();                   // 'credit','debit','prepaid'
            $table->char('country', 10)->nullable();                      // issuing country (BIN derived)
            $table->string('fingerprint', 64)->nullable()->index();      // gateway-provided fingerprint

            // Billing data (non-sensitive PII)
            $table->string('billing_name')->nullable();
            $table->string('billing_email')->nullable();
            $table->string('billing_phone')->nullable();
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('address_city')->nullable();
            $table->string('address_state')->nullable();
            $table->string('address_postal_code')->nullable();
            $table->string('address_zip_code')->nullable();
            $table->char('address_country', 10)->nullable();

            $table->boolean('is_default')->default(false);
            $table->text('metadata')->nullable();

            $table->index(['payment_provider']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('billable_payment_methods');
    }
};
