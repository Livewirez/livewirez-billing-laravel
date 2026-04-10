<?php 

namespace Livewirez\Billing\Models;

use Livewirez\Billing\Billing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingDiscountCodePaymentProviderInformation extends Model 
{
    protected $fillable = [
        'payment_provider', 
        'payment_provider_discount_code_id',
        'billing_product_id',
        'billing_plan_price_id',
        'code',
        'type',
        'metadata'
    ];

    protected $casts = [
        'metadata' => 'array'
    ];

    public function billing_product(): BelongsTo
    {
        return $this->belongsTo(Billing::$billingProduct, 'billing_product_id');
    }

    public function billing_plan_price(): BelongsTo
    {
        return $this->belongsTo(
            Billing::$billingPlanPrice,
            'billing_plan_price_id'
        );
    }

    public function billing_discount_code(): BelongsTo
    {
        return $this->belongsTo(Billing::$billingDiscountCode, 'billing_discount_code_id');
    } 
}