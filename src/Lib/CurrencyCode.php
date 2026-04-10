<?php 

namespace Livewirez\Billing\Lib;

use Livewirez\Billing\Lib\FiatCurrencyCode;

class CurrencyCode
{
 
    /* Methods */
    public static function from(int|string $value): FiatCurrencyCode | CryptoCurrencyCode
    {
        return match ($value) {
            FiatCurrencyCode::tryFrom($value)?->value => FiatCurrencyCode::from($value),
            CryptoCurrencyCode::tryFrom($value)?->value => CryptoCurrencyCode::from($value),
            default => throw new \InvalidArgumentException("Invalid currency code"),
        };
    }

    public static function tryFrom(int|string $value): FiatCurrencyCode | CryptoCurrencyCode | null
    {
        return match ($value) {
            FiatCurrencyCode::tryFrom($value)?->value => FiatCurrencyCode::from($value),
            CryptoCurrencyCode::tryFrom($value)?->value => CryptoCurrencyCode::from($value),
            default => null,
        };
    }
}