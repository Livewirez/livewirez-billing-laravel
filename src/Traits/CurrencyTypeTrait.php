<?php

namespace Livewirez\Billing\Traits;

use DomainException;
use Livewirez\Billing\Enums\CurrencyType;
use Livewirez\Billing\Lib\FiatCurrencyCode;
use Livewirez\Billing\Lib\CryptoCurrencyCode;

trait CurrencyTypeTrait
{
    public function isCrypto(): bool
    {
        return $this instanceof CryptoCurrencyCode;
    }

    public function isFiat(): bool
    {
        return $this instanceof FiatCurrencyCode;
    }

    public function getCurrencyType(): CurrencyType
    {
        if ($this->isFiat()) 
            return CurrencyType::FIAT;

        if ($this->isCrypto()) 
            return CurrencyType::CRYPTO;

        throw new DomainException('Unsupported Currency Type');
    }
}