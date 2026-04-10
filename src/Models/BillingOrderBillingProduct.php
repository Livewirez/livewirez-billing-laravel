<?php

namespace Livewirez\Billing\Models;

use Livewirez\Billing\Billing;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingOrderBillingProduct extends Pivot
{

    protected $fillable = [
        'quantity'
    ];

   public function billing_product(): BelongsTo
    {
        return $this->belongsTo(
            Billing::$billingProduct,
            'billing_product_id'
        );
    }

    public function billing_order(): BelongsTo
    {
        return $this->belongsTo(
            Billing::$billingOrder,
            'billing_order_id'
        );
    }
}