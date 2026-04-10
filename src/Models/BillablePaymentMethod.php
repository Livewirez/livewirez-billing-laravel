<?php 

namespace Livewirez\Billing\Models;

use Illuminate\Support\Str;
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

class BillablePaymentMethod extends Model
{
    protected static function booted(): void
    {
        static::creating(static function (self $model) {
            $model->billable_payment_method_id = Str::uuid();
        });
        
        static::saving(static function (self $model) {
            $model->billable_user_key = "{$model->billable_type}:{$model->billable_id}:{$model->payment_provider_user_id}";
        });
    }

    protected $fillable = [
        'payment_provider',
        'sub_payment_provider',
        'payment_provider_user_id',
        'payment_provider_method_id',
        'billable_payment_method_id',
        'token',
        'brand',
        'exp_month',
        'exp_year',
        'last4',
        'funding',
        'country',
        'fingerprint',
        'billing_name',
        'billing_email',
        'billing_phone',
        'address_line1',
        'address_line2',
        'address_city',
        'address_state',
        'address_postal_code',
        'address_country',
        'is_default',
        'metadata',
    ];

    protected $casts = [
        'token' => 'encrypted',
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

    public function billable_payment_provider_information(): BelongsTo
    {
        return $this->belongsTo(Billing::$billablePaymentProviderInformation);
    }
}