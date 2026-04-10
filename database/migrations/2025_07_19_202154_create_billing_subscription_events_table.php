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
        Schema::create('billing_subscription_events', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->uuid('billing_subscription_event_id')->unique();
            $table->foreignId('billing_subscription_id')->constrained()->onDelete('cascade');
            $table->foreignId('billing_subscription_transaction_id')->nullable()->constrained()->onDelete('set null');

            $table->string('type')->default('initial'); 
            $table->string('description', 5000)->nullable(); 
            // initial, renewal, upgrade, downgrade, retry, cancellation, pause, expiration

            $table->string('triggered_by')->default('SYSTEM'); // system, user, admin, webhook
            $table->text('details')->nullable();
            $table->text('metadata')->nullable();

            $table->index('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_subscription_events');
    }
};
