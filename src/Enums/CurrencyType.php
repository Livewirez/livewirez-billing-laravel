<?php

namespace Livewirez\Billing\Enums;

use Livewirez\Billing\Traits\ArchTechTrait;

enum CurrencyType: string
{
    use ArchTechTrait;

    case FIAT = 'FIAT';

    case CRYPTO = 'CRYPTO';
}