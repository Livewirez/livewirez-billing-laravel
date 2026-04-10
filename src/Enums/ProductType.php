<?php

namespace Livewirez\Billing\Enums;

use Livewirez\Billing\Traits\ArchTechTrait;

enum ProductType: string
{
    use ArchTechTrait;

    case DIGITAL = 'digital';

    case DOWNLOADABLE = 'downloadable';

    case PHYSICAL = 'physical';

    case SERVICE = 'service';

    case DONATION = 'donation';
}