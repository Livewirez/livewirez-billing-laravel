<?php

namespace Livewirez\Billing\Models;

use Illuminate\Support\Str;
use Livewirez\Billing\Money;
use Livewirez\Billing\Billing;
use Illuminate\Database\Eloquent\Model;
use Livewirez\Billing\Enums\ProductType;
use Livewirez\Billing\Enums\CurrencyType;
use Livewirez\Billing\Enums\PaymentProvider;
use Livewirez\Billing\Enums\ProductCategory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Livewirez\Billing\Traits\BillingProductTrait;
use Livewirez\Billing\Interfaces\ProductInterface;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Livewirez\Billing\Models\Casts\PaymentProviderCast;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class BillingProductPaymentProviderInformation extends Model
{

    protected $fillable = [
        'payment_provider',
        'payment_provider_product_id',
        'payment_provider_price_id',
        'metadata',
        'payment_provider_media_id',
        'payment_provider_price_ids',
        'payment_provider_media_ids',
        'is_archived',
        'is_active'
    ];

    public function casts()
    {
        return [
            'metadata' => 'array',
            'payment_provider_price_ids' => 'array',
            'payment_provider_media_ids' => 'array',
            'payment_provider' => PaymentProviderCast::class,
        ];
    }

    public function billing_product(): BelongsTo
    {
        return $this->belongsTo(Billing::$billingProduct, 'billing_product_id');
    }

}