<?php 

namespace Livewirez\Billing\Models\Casts;

use Livewirez\Billing\Enums\PaymentProvider;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

class PaymentProviderCast implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes)
    {
        return PaymentProvider::tryFrom($value) ?: $value;
    }

    public function set($model, string $key, $value, array $attributes)
    {
        return $value instanceof PaymentProvider ? $value->value : $value;
    }
}