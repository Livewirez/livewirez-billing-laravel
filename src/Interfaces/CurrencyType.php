<?php 

namespace Livewirez\Billing\Interfaces;

use Livewirez\Billing\Enums\CurrencyType as EnumsCurrencyType;

interface CurrencyType
{
    public function isCrypto(): bool;

    public function isFiat(): bool;

    public function getCurrencyType(): EnumsCurrencyType;
}