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
        Schema::create('billing_currency_conversion_rates', function (Blueprint $table) {
            // A unique identifier for each exchange rate record.
            $table->id();
            
            // The base currency code (e.g., 'USD' from API 1, 'EUR' from API 2).
            // We use a fixed-length string (3 characters) for ISO 4217 codes.
            $table->string('base_code', 10)->index();
            
            // The target currency code that the base currency is converted to.
            // (e.g., 'AUD', 'GBP', 'JPY').
            $table->string('target_code', 10)->index();
            
            // The exchange rate value. The decimal type with a high precision (20 total digits, 8 after the decimal)
            // is crucial for accurately storing rates, which can have many decimal places.
            $table->decimal('rate', 20, 8);
            
            // The Unix timestamp of the exchange rate data from the API.
            // This field is nullable because some API endpoints might not provide it,
            // or the date field might be the primary source of truth for historical data.
            $table->unsignedBigInteger('timestamp')->nullable();
            
            // The date of the exchange rate data from the API.
            // This field is nullable as some APIs might only provide a timestamp.
            $table->timestamp('date')->nullable();

            // the source url of the api
            $table->string('url')->nullable();
            
            // Laravel's built-in timestamps (created_at and updated_at) to track when the record was added
            // or last updated in our database, separate from the API's own timestamps.
            $table->timestamps();

            // A unique constraint is added to prevent duplicate records. This ensures that for a specific
            // base currency, target currency, and timestamp, only one record can exist. This is essential
            // for data integrity and idempotency when ingesting from APIs.
            $table->unique(['base_code', 'target_code', 'timestamp'], 'unique_currency_rate');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_currency_conversion_rates');
    }
};
