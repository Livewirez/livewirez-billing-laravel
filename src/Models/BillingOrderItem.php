<?php 

namespace Livewirez\Billing\Models;

use Illuminate\Support\Str;
use Livewirez\Billing\Money;
use Livewirez\Billing\Billing;
use Illuminate\Foundation\Auth\User;
use Illuminate\Database\Eloquent\Model;
use Livewirez\Billing\Enums\OrderStatus;
use Livewirez\Billing\Enums\CurrencyType;
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

class BillingOrderItem extends Model
{
    protected static function booted(): void
    {
        static::creating(static function (self $model) {
            $model->billing_order_item_id = Str::uuid();
        });
    }

    protected $fillable = [
        'billing_product_id',
        'billing_order_item_id',
        'billing_plan_id',
        'billing_plan_price_id', 
        'name',
        'price',
        'thumbnail',
        'url',
        'quantity',
        'currency',
        'subtotal', // before tax & shipping
        'discount',
        'tax',
        'shipping',
        'total',
        'type',
        'status',
        'payment_status',
        'delivery_status',
        'fulfillment_status',
        'shipped_at',
        'tracking_number',
        'carrier',
        'metadata',
        'options',
        'processed_at'
    ];

    protected $casts = [
        'payment_provider' => PaymentProviderCast::class,
        'metadata' => 'array',
        'options' => 'array',
        'processed_at' => 'datetime',
        'status' => OrderStatus::class,
        'delivery_status' => DeliveryStatus::class,
        'fulfillment_status' => FulfillmentStatus::class
    ];

        protected $appends = [
        'formatted_price',
        'formatted_subtotal',
        'formatted_discount',
        'formatted_tax',
        'formatted_shipping',
        'formatted_total',
        'currency_code'
    ];

    protected function formattedPrice(): Attribute
    {
        return Attribute::make(
            get: fn () => Money::formatAmountUsingCurrency($this->price, $this->currency),
        );
    }

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


    public function billing_payment_transaction(): HasOne
    {
        return $this->hasOne(Billing::$billingPaymentTransaction, 'billing_order_id');
    }

    public function billing_product(): BelongsTo
    {
        return $this->belongsTo(Billing::$billingProduct, 'billing_product_id');
    }

    public function billing_order(): BelongsTo
    {
        return $this->belongsTo(Billing::$billingOrder, 'billing_order_id');
    }

    public function billing_plan(): BelongsTo 
    {
        return $this->belongsTo(Billing::$billingPlan, 'billing_plan_id');
    }

    public function billing_plan_price(): BelongsTo 
    {
        return $this->belongsTo(Billing::$billingPlanPrice, 'billing_plan_price_id');
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
}