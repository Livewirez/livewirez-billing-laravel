<?php 

namespace Livewirez\Billing\Models;

use Livewirez\Billing\Billing;
use Illuminate\Foundation\Auth\User;
use Illuminate\Database\Eloquent\Model;
use Livewirez\Billing\Enums\CurrencyType;
use Livewirez\Billing\Enums\PaymentStatus;
use Livewirez\Billing\Enums\PaymentProvider;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Livewirez\Billing\Models\Casts\PaymentProviderCast;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class BillablePaymentProviderInformation extends Model
{
    protected $fillable = [
        'payment_provider',
        'payment_provider_user_id',
        'metadata',
    ];

    protected $casts = [
        'payment_provider' => PaymentProviderCast::class,
        'metadata' => 'array',
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

    public function billable_address(): BelongsTo
    {
        return $this->belongsTo(Billing::$billableAddress, 'billing_address_id');
    }

    public function billable_payment_methods(): HasMany
    {
        return $this->hasMany(Billing::$billablePaymentMethod, 'billable_payment_information_id');
    }
}