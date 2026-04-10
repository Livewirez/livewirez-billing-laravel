<?php 

namespace Livewirez\Billing\Models;

use Illuminate\Support\Str;
use Livewirez\Billing\Billing;
use Illuminate\Foundation\Auth\User;
use Illuminate\Database\Eloquent\Model;
use Livewirez\Billing\Enums\ActionType;
use Livewirez\Billing\Enums\EntityType;
use Livewirez\Billing\Enums\CurrencyType;
use Livewirez\Billing\Enums\PaymentProvider;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Livewirez\Billing\Enums\PaymentTransactionType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Livewirez\Billing\Enums\PaymentTransactionStatus;
use Livewirez\Billing\Models\Casts\PaymentProviderCast;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class BillingPaymentTransaction extends Model
{
    protected $fillable = [
        'billing_payment_transaction_id',
        'payment_provider_subscription_id',
        'payment_provider_checkout_id',
        'payment_provider_transaction_id',
        'payment_provider_invoice_id',
        'payment_provider_invoice_number',
        'action_type',
        'type',
        'status',
        'total_amount',
        'discount',
        'earnings',
        'subtotal',
        'tax',
        'provider_fee',
        'currency',
        'payment_provider',
        'sub_payment_provider',
        'metadata',
        'transacted_at'
    ];

    protected $casts = [
        'action_type' => ActionType::class,
        'payment_provider' => PaymentProviderCast::class,
        'metadata' => 'array',
        'transacted_at' => 'datetime',
        'type' => PaymentTransactionType::class,
        'status' => PaymentTransactionStatus::class
    ];

    /**
     * Get the passkey_user model that the access token belongs to.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function billable(): MorphTo
    {
        return $this->morphTo('billable');
    }

    public function billing_order(): BelongsTo 
    {
        return $this->belongsTo(Billing::$billingOrder, 'billing_order_id');
    }

    public function billing_subscription(): BelongsTo 
    {
        return $this->belongsTo(Billing::$billingSubscription, 'billing_subscription_id');
    }

    public function billing_transaction_data(): HasMany
    {
        return $this->hasMany(Billing::$billingTransactionData, 'billing_payment_transaction_id');
    }

    public function billing_products(): HasManyThrough
    {
        return $this->hasManyThrough(
            Billing::$billingProduct,
            Billing::$billingOrderBillingProduct,
            'billing_order_id', // Foreign key on BillingOrder
            'id',         // Local key on Product
            'billing_order_id', 
            'billing_product_id' 
        );
    }

    public static function gen_unique_id(): string
    {
        return rand(1000, 9999) . '-' . Str::random(5) . '-' . time();
    }
}