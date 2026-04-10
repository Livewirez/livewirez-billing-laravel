<?php 

namespace Livewirez\Billing\Enums;

use Livewirez\Billing\Traits\ArchTechTrait;

enum SubscriptionTransactionType: string
{
    use ArchTechTrait;

    case Initial     = 'initial';   // first payment or activation
    case Renewal     = 'renewal';   // recurring billing
    case Upgrade     = 'upgrade';   // plan upgrade
    case Downgrade   = 'downgrade'; // plan downgrade
    case Retry       = 'retry';     // payment retry
    case PriceIncrease = 'price_increase';
    case PriceDecrease = 'price_decrease';

    case Static      = 'static';
}