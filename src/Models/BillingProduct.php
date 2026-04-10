<?php

namespace Livewirez\Billing\Models;

use Illuminate\Support\Str;
use Livewirez\Billing\Money;
use Livewirez\Billing\Billing;
use Illuminate\Database\Eloquent\Model;
use Livewirez\Billing\Enums\ProductType;
use Livewirez\Billing\Enums\CurrencyType;
use Livewirez\Billing\Enums\ProductCategory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Livewirez\Billing\Traits\BillingProductTrait;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Livewirez\Billing\Interfaces\ProductInterface;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class BillingProduct extends Model implements ProductInterface
{
    use BillingProductTrait;

    protected static function booted(): void
    {
        parent::booted();

        static::creating(static function (self $model) {
            $model->billing_product_id = Str::uuid();
        });
    }

    protected $attributes = [
        'product_type' => ProductType::PHYSICAL,
        'product_category' => ProductCategory::PHYSICAL_GOODS,
    ];

    protected $hidden = [
        'metadata',
    ];

    protected $fillable = [
        'name',
        'description',
        'price',
        'currency',
        'scale',
        'url',
        'thumbnail',
        'images',
        'sku',
        'product_type', // physical, digital
        'billing_product_id',
        'tax', 
        'tax_type', // percent
        'tax_model',
        'discount', // Percentage discount
        'colour',
        'discount_percentage',
        'shipping',
        'shipping_discount',
        'handling',
        'insurance',
        'discount_expires_at', // Expiration date
        'is_active',
        'weight',
        'brand',
        'stock',
        'modifier_scale',
        'metadata'
    ];

    public function casts()
    {
        return [
            'metadata' => 'array',
            'images' => 'json',
            'discount' => 'float',
            'discount_percentage' => 'float',
            'shipping' => 'float',
            'shipping_discount' => 'float',
            'handling' => 'float',
            'insurance' => 'float',
            'product_type' => ProductType::class,
            'product_category' => ProductCategory::class
        ];
    }

    protected $appends = [
        'formatted_price',
    ];

    protected function formattedPrice(): Attribute
    {
        return Attribute::make(
            get: fn () => Money::formatAmountUsingCurrency($this->price, $this->currency),
        );
    }

    public function isDiscountActive(): bool
    { 
        return $this->discount_expires_at && now()->lt($this->discount_expires_at);
    }

    public function billing_discount_code(): BelongsTo
    {
        return $this->belongsTo(Billing::$billingDiscountCode, 'billing_discount_code_id');
    } 

    public function billing_orders(): BelongsToMany
    {
        return $this->belongsToMany(
            Billing::$billingOrder,
            foreignPivotKey: 'billing_product_id',
            relatedPivotKey: 'billing_order_id'
        )->using(Billing::$billingOrderBillingProduct);
    }

    public function billing_payment_transactions(): HasManyThrough
    {
        return $this->hasManyThrough(
            Billing::$billingPaymentTransaction,
            Billing::$billingOrderBillingProduct,
            'billing_product_id', // Foreign key on BillingProduct
            'id',         // Local key on BillingProduct
            'billing_product_id', // Foreign key on BillingPaymentTransaction (via payment relation)
            'billing_order_id'  // Foreign key on BillingOrderBillingProduct to BillingProduct
        );
    }

    public function billing_plans(): HasMany
    {
        return $this->hasMany(Billing::$billingPlan, 'billing_product_id');
    }

    public function billing_cart_items(): HasMany
    {
        return $this->hasMany(Billing::$billingCartItem, 'billing_product_id');
    }

    public function billing_order_items(): HasMany
    {
        return $this->hasMany(Billing::$billingOrderItem, 'billing_product_id');
    }

    public function billing_product_payment_provider_information(): HasMany
    {
        return $this->hasMany(Billing::$billingProductPaymentProviderInformation, 'billing_product_id');
    }

    public function billing_plan_prices(): HasManyThrough
    {
        return $this->hasManyThrough(
            Billing::$billingPlanPrice,
            Billing::$billingPlan,
            'billing_product_id',
            'billing_plan_id',
        );
    }

    public function billing_discount_code_payment_provider_information(): HasOne
    { 
        return $this->hasOne(Billing::$billingDiscountCodePaymentProviderInformation, 'billing_discount_code_id');
    }
}
