<?php 

namespace Livewirez\Billing\Traits;

use Illuminate\Database\Eloquent\Casts\Attribute;

trait HasScaledAmount
{
    protected function tax(): Attribute
    {
        return Attribute::make(
            get: fn () => bcdiv((string) $this->tax, bcpow('10', (string) $this->tax_scale), $this->tax_scale),
            set: function (string $value) {
                $scale = $this->tax_scale ?? 2;
                $this->attributes['tax'] = (int) bcmul((string) $value, bcpow('10', (string) $scale));
                $this->attributes['tax_scale'] = $scale;
            }
        );
    }

    protected function discount(): Attribute
    {
        return Attribute::make(
            get: fn () => bcdiv((string) $this->discount, bcpow('10', (string) $this->discount_scale), $this->discount_scale),
            set: function (string $value) {
                $scale = $this->discount_scale ?? 2;
                $this->attributes['discount'] = (int) bcmul((string) $value, bcpow('10', (string) $scale));
                $this->attributes['discount_scale'] = $scale;
            }
        );
    }
}