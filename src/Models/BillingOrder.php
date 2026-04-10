<?php 

namespace Livewirez\Billing\Models;

use Illuminate\Support\Str;
use Livewirez\Billing\Money;
use Livewirez\Billing\Billing;
use Illuminate\Foundation\Auth\User;
use Illuminate\Database\Eloquent\Model;
use Livewirez\Billing\Enums\OrderStatus;
use Livewirez\Billing\Enums\CurrencyType;
use Livewirez\Billing\Enums\PaymentStatus;
use Livewirez\Billing\Enums\DeliveryStatus;
use Livewirez\Billing\Enums\PaymentProvider;
use Livewirez\Billing\Enums\FulfillmentStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Livewirez\Billing\Models\Casts\PaymentProviderCast;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class BillingOrder extends Model
{
    protected static function booted(): void
    {
        static::creating(static function (self $order) {
            $order->billing_order_id = Str::uuid();
        });
    }

    protected $fillable = [
        'billing_order_id',
        'order_number',
        'status',
        'currency',
        'subtotal',
        'discount',
        'tax',
        'shipping',
        'total',
        'payment_status',
        'payment_provider', // 'paypal', 'stripe', 'mpesa', etc.
        'sub_payment_provider',
        'payment_provider_order_id', // external payment reference
        'payment_provider_checkout_id', // external payment reference
        'payment_provider_transaction_id',
        'metadata',
        'processed_at'
    ];

    protected $casts = [
        'payment_provider' => PaymentProviderCast::class,
        'metadata' => 'array',
        'processed_at' => 'datetime',
        'status' => OrderStatus::class,
        'payment_status' => PaymentStatus::class
    ];

    protected $appends = [
        'formatted_subtotal',
        'formatted_discount',
        'formatted_tax',
        'formatted_shipping',
        'formatted_total',
        'currency_code'
    ];

    protected function formattedSubtotal(): Attribute
    {
        return Attribute::make(
            get: fn () => Money::formatAmountUsingCurrency($this->subtotal, $this->currency),
        );
    }

    protected function formattedDiscount(): Attribute
    {
        return Attribute::make(
            get: fn () => Money::formatAmountUsingCurrency($this->discount, $this->currency),
        );
    }

    protected function formattedTax(): Attribute
    {
        return Attribute::make(
            get: fn () => Money::formatAmountUsingCurrency($this->tax, $this->currency),
        );
    }

    protected function formattedShipping(): Attribute
    {
        return Attribute::make(
            get: fn () => Money::formatAmountUsingCurrency($this->shipping, $this->currency),
        );
    }

    protected function formattedTotal(): Attribute
    {
        return Attribute::make(
            get: fn () => Money::formatAmountUsingCurrency($this->total, $this->currency),
        );
    }

    protected function currencyCode(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->currency,
        );
    }

    /**
     * Get the passkey_user model that the access token belongs to.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function billable(): MorphTo
    {
        return $this->morphTo('billable');
    }

    public function billing_address(): BelongsTo
    {
        return $this->belongsTo(Billing::$billableAddress, 'billing_address_id');
    }

    public function shipping_address(): BelongsTo
    {
        return $this->belongsTo(Billing::$billingOrderShippingAddress, 'billing_order_shipping_address_id');
    }

    public function billing_subscription(): BelongsTo 
    {
        return $this->belongsTo(Billing::$billingSubscription, 'billing_subscription_id');
    }

    public function billing_payment_transaction(): HasOne
    {
        return $this->hasOne(Billing::$billingPaymentTransaction, 'billing_order_id');
    }

    public function billing_cart(): HasOne
    {
        return $this->hasOne(Billing::$billingCart, 'billing_order_id');
    }

    public function billing_order_items(): HasMany
    {
        return $this->hasMany(Billing::$billingOrderItem, 'billing_order_id');
    }

    public function billing_products(): BelongsToMany
    {
        return $this->belongsToMany(
            Billing::$billingProduct,
            foreignPivotKey: 'billing_order_id',
            relatedPivotKey: 'billing_product_id'
        )->using(Billing::$billingOrderBillingProduct);
    }

    public function isCompleted()
    {
        return $this->status === OrderStatus::COMPLETED;
    }

    public function isFailed()
    {    
        return $this->status === OrderStatus::FAILED;
    }

    public function isPending()
    {
        return $this->status === OrderStatus::PENDING;
    }

    public function isDelivered()
    {
        return $this->delivery_status === DeliveryStatus::DELIVERED;
    }

    public function isFulfilled()
    {
        return $this->fulfillment_status === FulfillmentStatus::FULFILLED;
    }

    public static function generateOrderNumber(bool $short = false): string 
    {
        $year = date('Y'); 
        $prefix = "ORD-{$year}-";
        
        return $short ? strtoupper(uniqid($prefix)) : $prefix .  Billing::makeUniqueId();
    }

}